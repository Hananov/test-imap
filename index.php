<?php

require_once 'config/config_db.php';
$config = parse_ini_file('config/config_imap.ini', true);

class HandlerMail
{
    private $iopen;
    public $host;
    public $count_messages;

    public static function app($host, $username, $password)
    {
        return new self($host, $username, $password);
    }

    private function __construct($host, $username, $password)
    {
        $this->log('Connect imap');
        $this->host = $host;
        $this->iopen = imap_open($host, $username, $password) or die("can't connect: " . imap_last_error());
        $this->count_messages = imap_num_msg($this->iopen);
        return $this;
    }

    //Получить кодировку сообщения
    public function getEncodingType($messageId)
    {
        $this->log('Get encoding type');
        $encodings = array(
            0 => '7BIT',
            1 => '8BIT',
            2 => 'BINARY',
            3 => 'BASE64',
            4 => 'QUOTED-PRINTABLE',
            5 => 'OTHER',
        );

        $structure = imap_fetchstructure($this->iopen, $messageId);
        return $encodings[$structure->encoding];
    }

    //Структура сообщения
    public function getStructure($messageId)
    {
        $this->log('Get structure mail');
        return imap_fetchstructure($this->iopen, $messageId);
    }


    private function log($data)
    {
        $data = date('[Y-m-d H:i:s] - ') . $data . PHP_EOL;
        file_put_contents('imaplog.txt', $data, FILE_APPEND);
    }

    //Получить номера вхрдящих сообщений
    public function getMessagesIds()
    {
        $this->log('Connect ids messages');
        $num_messages = imap_num_msg($this->iopen);
        if ($num_messages) {
            $overviews = imap_fetch_overview($this->iopen, "1:" . imap_num_msg($this->iopen), 0);
        } else {
            $overviews = array();
        }
        $messageArray = array();
        foreach ($overviews as $overview) {
            $messageArray[$overview->msgno] = $overview->subject;
        }
        return $messageArray;
    }

    public function decode7Bit($text)
    {
        $this->log('Decode7Bit');
        $lines = explode("\r\n", $text);
        $first_line_words = explode(' ', $lines[0]);
        if ($first_line_words[0] == $lines[0]) {
            if (base64_decode($text, true)) {
                $text = base64_decode($text);
            }
        }

        $characters = array(
            '=20' => ' ', // space.
            '=2C' => ',', // comma.
            '=E2=80=99' => "'", // single quote.
            '=0A' => "\r\n", // line break.
            '=0D' => "\r\n", // carriage return.
            '=A0' => ' ', // non-breaking space.
            '=B9' => '$sup1', // 1 superscript.
            '=C2=A0' => ' ', // non-breaking space.
            "=\r\n" => '', // joined line.
            '=E2=80=A6' => '&hellip;', // ellipsis.
            '=E2=80=A2' => '&bull;', // bullet.
            '=E2=80=93' => '&ndash;', // en dash.
            '=E2=80=94' => '&mdash;', // em dash.
        );

        foreach ($characters as $key => $value) {
            $text = str_replace($key, $value, $text);
        }
        return $text;
    }

    //Получить сообщение по id
    public function getMessage($id)
    {
        $this->log('Get message');
        $header = imap_headerinfo($this->iopen, $id);
        if ($header) {
            $body = imap_fetchbody($this->iopen, $id, 1.2);
            if (!strlen($body) > 0) {
                $body = imap_fetchbody($this->iopen, $id, 1);
            }
            $encoding = $this->getEncodingType($id);
            if ($encoding == 'BASE64') {
                $body_clnhtml = strip_tags(imap_base64($body));
                $body = imap_base64($body);
            } elseif ($encoding == 'QUOTED-PRINTABLE') {
                $body_clnhtml = strip_tags(quoted_printable_decode($body));
                $body = quoted_printable_decode($body);
            } elseif ($encoding == '8BIT') {
                $body_clnhtml = strip_tags(quoted_printable_decode(imap_8bit($body)));
                $body = quoted_printable_decode(imap_8bit($body));
            } elseif ($encoding == '7BIT') {
                $body_clnhtml = strip_tags($this->decode7Bit($body));
                $body = $this->decode7Bit($body);
            }

            $message = [
                'from' => imap_mime_header_decode($header->fromaddress)[0]->text,
                'to' => imap_mime_header_decode($header->toaddress)[0]->text,
                'subject' => imap_mime_header_decode($header->subject)[0]->text,
                'body' => $body,
                'body_clnhtml' => $body_clnhtml,
            ];

            //Вложения
            $attachments = [];
            if (isset($this->getStructure($id)->parts) && count($this->getStructure($id)->parts)) {
                $this->log('Get attachments');
                for ($i = 0; $i < count($this->getStructure($id)->parts); $i++) {
                    $attachments[$i] = array(
                        'is_attachment' => false,
                        'filename' => '',
                        'name' => '',
                        'attachment' => ''
                    );

                    if ($this->getStructure($id)->parts[$i]->ifdparameters) {
                        foreach ($this->getStructure($id)->parts[$i]->dparameters as $object) {
                            if (strtolower($object->attribute) == 'filename') {
                                $attachments[$i]['is_attachment'] = true;
                                $attachments[$i]['filename'] = $object->value;
                            }
                        }
                    }

                    if ($this->getStructure($id)->parts[$i]->ifparameters) {
                        foreach ($this->getStructure($id)->parts[$i]->parameters as $object) {
                            if (strtolower($object->attribute) == 'name') {
                                $attachments[$i]['is_attachment'] = true;
                                $attachments[$i]['name'] = $object->value;
                            }
                        }
                    }

                    if ($attachments[$i]['is_attachment']) {
                        $attachments[$i]['attachment'] = imap_fetchbody($this->iopen, $id, $i + 1);
                        if ($this->getStructure($id)->parts[$i]->encoding == 3) {
                            $attachments[$i]['attachment'] = base64_decode($attachments[$i]['attachment']);
                        } elseif ($this->getStructure($id)->parts[$i]->encoding == 4) {
                            $attachments[$i]['attachment'] = quoted_printable_decode($attachments[$i]['attachment']);
                        }
                    }
                }
            }
            $attachments_path = '';
            foreach ($attachments as $attachment) {
                if ($attachment['is_attachment'] == 1) {
                    $filename = $attachment['name'];
                    if (empty($filename)) {
                        $filename = $attachment['filename'];
                    }

                    if (empty($filename)) {
                        $filename = time() . ".dat";
                    }
                    $folder = "attachment";
                    if (!is_dir($folder)) {
                        mkdir($folder);
                    }
                    file_put_contents("./" . $folder . "/" . $id . "-" . $filename, $attachment['attachment']);
                    $attachments_path .= "./" . $folder . "/" . $id . "-" . $filename . ';';
                }
            }
            $this->setDb($message, $attachments_path);
            $this->deleteMessage($id);
        } else {
            throw new Exception("Message could not be found: " . imap_last_error());
        }
        return $message;
    }

    //Записать в базу
    public function setDb($message, $attachments_path = '')
    {
        $this->log('Db insert message ');
        $dsn = 'mysql:host=' . HOST . ';dbname=' . DB . ';charset=' . CHARSET;
        $pdo = new PDO($dsn, USER, PASSWORD);
        $query = "INSERT INTO messages (from_author,to_email,body,body_clean_html,attachment) VALUES (:from_author,:to_email,:body,:body_clean_html,:attachment)";
        $q = $pdo->prepare($query);
        if (empty($message['to'])) {
            $message['to'] = '';
        }
        $q->execute([
            'from_author' => $message['from'],
            'to_email' => $message['to'],
            'body' => $message['body'],
            'body_clean_html' => $message['body_clnhtml'],
            'attachment' => $attachments_path
        ]);

        if ($q) {
            $this->sendEmail($message['subject']);
        }
        if ($q->errorCode() != PDO::ERR_NONE) {
            $info = $q->errorInfo();
            die($info[2]);
        }
    }

    //Удалить сообщения из ящика
    public function deleteMessage($messageId)
    {
        $this->log('Delete messages');
        if (!imap_delete($this->iopen, $messageId)) {
            throw new Exception("Message could not be deleted: " . imap_last_error());
        }
    }

    //Отправка уведомления о новых сообщениях
    private function sendEmail($subject)
    {
        $this->log('Send status new email');
        $to = STATUS_EMAIL;
        $subject = 'the subject';
        $message = 'Получено новое сообщение';
        $headers = 'From: webmaster@example.com' . "\r\n" .
            'Reply-To: webmaster@example.com' . "\r\n" .
            'X-Mailer: PHP/' . phpversion();

        mail($to, $subject, $message, $headers);
    }

    //Разорвать соединение
    public function disconnect()
    {
        $this->log('Imap disconnect');
        imap_close($this->iopen, CL_EXPUNGE);
    }

    public function __destruct()
    {
        $this->log('Destruct imap');
        imap_close($this->iopen, CL_EXPUNGE);
    }
}

//Инициализация подключения
$connect_mail = HandlerMail::app($config['imap']['yandex_init'], $config['yandex_mail']['user_name'],
    $config['yandex_mail']['password']);
//Массив с номерами сообщений
$num_messages = $connect_mail->getMessagesIds();

//Обработка сообщений
foreach ($num_messages as $k => $v) {
    $connect_mail->getMessage($k);
}
