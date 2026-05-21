<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/RedsysAPI.php';
require_once __DIR__ . '/../lib/Mailer.php';

function nlog($m){$d=__DIR__.'/../logs';if(!is_dir($d))@mkdir($d,0750,true);file_put_contents($d.'/notify.log',date('[Y-m-d H:i:s] ').$m.PHP_EOL,FILE_APPEND|LOCK_EX);}

$params   = isset($_POST['Ds_MerchantParameters'])?$_POST['Ds_MerchantParameters']:'';
$sig      = isset($_POST['Ds_Signature'])?$_POST['Ds_Signature']:'';
if(!$params||!$sig){nlog('Sin parametros. IP:'.($_SERVER['REMOTE_ADDR']??''));http_response_code(400);exit('KO');}

$r    = new RedsysAPI();
$data = $r->getDecodedMerchantParameters($params);
$ord  = isset($data['DS_ORDER'])?$data['DS_ORDER']:(isset($data['DS_MERCHANT_ORDER'])?$data['DS_MERCHANT_ORDER']:'');
$resp = isset($data['DS_RESPONSE'])?$data['DS_RESPONSE']:'9999';
$auth = isset($data['DS_AUTHORISATIONCODE'])?$data['DS_AUTHORISATIONCODE']:'';

nlog("Notif Order:$ord Resp:$resp");
if(!$r->validateResponse(getSetting('redsys_secret_key'),$params,$sig,$ord)){nlog("Firma invalida Order:$ord");http_response_code(400);exit('KO');}

$st=db()->prepare('SELECT * FROM transactions WHERE order_ref=?');$st->execute(array($ord));$tx=$st->fetch();
if(!$tx){nlog("No encontrada Order:$ord");http_response_code(200);exit('OK');}
if($tx['status']!=='pending'){nlog("Ya procesada $ord estado:{$tx['status']}");http_response_code(200);exit('OK');}

$status=RedsysAPI::isResponseOk($resp)?'ok':'error';

// Historial de cambios de estado
$logEntry = date('d/m/Y H:i:s') . ' — ' . $tx['status'] . ' -> ' . $status . ' (resp:'.$resp.')';
$prevLog  = $tx['status_log'] ? $tx['status_log']."
".$logEntry : $logEntry;

db()->prepare('UPDATE transactions SET status=?,redsys_order=?,redsys_auth=?,redsys_response=?,redsys_merchant=?,status_log=?,updated_at=NOW() WHERE id=?')
   ->execute(array($status,$ord,$auth,$resp,json_encode($data),$prevLog,$tx['id']));
nlog("$ord -> $status");

if($status==='ok'){
    $st2=db()->prepare('SELECT * FROM transactions WHERE id=?');$st2->execute(array($tx['id']));$txd=$st2->fetch();
    $txd['redsys_auth']=$auth;
    $m=new Mailer(); $eok=true; $eerr='';
    if(!empty($txd['customer_email'])){
        $subj=str_replace(array('{concepto}','{nombre}'),array($txd['concept_name'],$txd['customer_name']),getSetting('mail_subject_cliente','Confirmacion de pago - {concepto}'));
        $res=$m->send($txd['customer_email'],$txd['customer_name'],$subj,Mailer::templateCliente($txd));
        if(!$res['ok']){$eok=false;$eerr.='Cliente:'.$res['error'].' | ';nlog('ERR email cliente:'.$res['error']);}
        else nlog('Email cliente OK:'.$txd['customer_email']);
    }
    $ae=getSetting('mail_admin_email');
    if($ae){
        $subj=str_replace(array('{concepto}','{nombre}'),array($txd['concept_name'],$txd['customer_name']),getSetting('mail_subject_admin','Nuevo pago - {concepto} - {nombre}'));
        $res=$m->send($ae,getSetting('mail_admin_name','Admin'),$subj,Mailer::templateAdmin($txd));
        if(!$res['ok']){$eok=false;$eerr.='Admin:'.$res['error'];nlog('ERR email admin:'.$res['error']);}
        else nlog('Email admin OK:'.$ae);
    }
    db()->prepare('UPDATE transactions SET email_sent=?,email_error=? WHERE id=?')->execute(array($eok?1:0,$eerr?$eerr:null,$tx['id']));
}
http_response_code(200);echo 'OK';
