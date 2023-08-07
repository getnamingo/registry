<?php namespace App\Lib;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
/**
 * Mail
 *
 * @author    Hezekiah O. <support@hezecom.com>
 */
class Mail
{

    public static function send($subject, $body, $from=[], $to=[], $info=[])
    {
        $mail = new PHPMailer(true);
        try {
            $mail->SMTPDebug = 0;
            if(envi('MAIL_DRIVER')=='smtp') {
                $mail->isSMTP();
                $mail->Host = envi('MAIL_HOST');
                $mail->SMTPAuth = true;
                $mail->Username = envi('MAIL_USERNAME');
                $mail->Password = envi('MAIL_PASSWORD');
                $mail->SMTPSecure = envi('MAIL_ENCRYPTION');
                $mail->Port = envi('MAIL_PORT');
            }
            elseif(envi('MAIL_DRIVER')=='sendmail') {
                $mail->isSendmail();
            }
            else{
                $mail->isMail();
            }

            $mail->setFrom($from['email'], $from['name']);
            $mail->addAddress($to['email'], $to['name']);
            //$mail->addAttachment('path/to/invoice1.pdf', 'invoice1.pdf');

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            //$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';
            $mail->send();
            //echo 'Message has been sent';
            return false;
        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    }
}
