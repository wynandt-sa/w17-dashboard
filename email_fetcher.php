<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php'; // load SMTP + app URL
$pdo=db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

// NEW: Stub for send_mail (actual implementation requires PHPMailer or similar)
if (!function_exists('send_mail')) {
    function send_mail(string $to, string $subject, string $body_html, ?string $from_email = null): bool {
        // In a real application, this would use the SMTP settings saved in system_settings.php.
        error_log("MAIL_SENT: To: $to, From: $from_email, Subject: $subject");
        return true;
    }
}


/* Load queues */
$queues=$pdo->query("SELECT * FROM queue_emails")->fetchAll(PDO::FETCH_ASSOC);

foreach($queues as $q){
  $email=$q['email'];
  // Retrieve credentials (pop/smtp creds are stored in system_settings, prefixed by the queue email)
  $host=setting($pdo,'pop_host_'.$email);
  $port=setting($pdo,'pop_port_'.$email) ?: 995;
  $user=setting($pdo,'pop_user_'.$email) ?: $email;
  $pass=setting($pdo,'pop_pass_'.$email);

  // Skip queue if critical creds are missing
  if (!$host || !$pass) continue;
  
  $mbox=@imap_open("{".$host.":".$port."/pop3/ssl}INBOX",$user,$pass);
  if(!$mbox) continue;

  $emails=imap_search($mbox,'UNSEEN');
  if($emails){
    foreach($emails as $num){
      $header=imap_headerinfo($mbox,$num);
      $from=$header->from[0]->mailbox.'@'.$header->from[0]->host;
      $subject=$header->subject ?: '(no subject)';
      $body=imap_fetchbody($mbox,$num,1);

      // insert ticket
      $tn=date('Ymd').'-'.str_pad($pdo->query("SELECT IFNULL(MAX(id),0)+1 FROM tickets")->fetchColumn(),4,'0',STR_PAD_LEFT);
      $ins=$pdo->prepare("INSERT INTO tickets(ticket_number,subject,requester_email,priority,status,description,queue,created_by) VALUES(?,?,?,?,?,?,?,NULL)");
      $ins->execute([$tn,$subject,$from,'Medium','New',$body,$q['queue']]);
      $tid=(int)$pdo->lastInsertId();

      // attachments
      $stParts = imap_fetchstructure($mbox,$num);
      if(isset($stParts->parts)){
        foreach($stParts->parts as $i=>$part){
          if($part->ifdparameters){
            foreach($part->dparameters as $obj){
              if(strtolower($obj->attribute)=='filename'){
                $att=imap_fetchbody($mbox,$num,$i+1);
                $fname=$obj->value;
                $path='uploads/tickets/'.uniqid('att_').'-'.$fname;
                file_put_contents(__DIR__.'/'.$path,imap_base64($att));
                $pdo->prepare("INSERT INTO ticket_attachments(ticket_id,path,original_name) VALUES(?,?,?)")->execute([$tid,$path,$fname]);
              }
            }
          }
        }
      }

      // send acknowledgement
      $tpl=$pdo->prepare("SELECT subject,body_html FROM email_templates WHERE template_type='new' LIMIT 1");
      $tpl->execute(); $t=$tpl->fetch(PDO::FETCH_ASSOC);
      if($t){
        $link = (defined('APP_BASE_URL')? rtrim(APP_BASE_URL,'/') : '').'/tickets.php#t'.$tid;
        $sub=str_replace(['{{ticket_number}}','{{ticket_link}}'],[$tn, $link],$t['subject']);
        $body=str_replace(['{{ticket_number}}','{{ticket_link}}'],[$tn, $link],$t['body_html']);
        send_mail($from,$sub,$body,$email);
      }
    }
  }
  imap_close($mbox);
}

function setting($pdo,$k){ $st=$pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?"); $st->execute([$k]); return $st->fetchColumn(); }
