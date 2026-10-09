<?php
declare(strict_types=1);
namespace App;

use PHPMailer\PHPMailer\PHPMailer;

final class Mail
{
    /** A test-only transport; never configured by an HTTP request. */
    public static ?\Closure $testTransport = null;
    public static function queue(?int $eventId, string $email, string $name, string $subject, string $html, string $text): void
    {
        DB::query('INSERT INTO mail_outbox(event_id, recipient, recipient_name, subject, html_body, text_body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$eventId, strtolower(trim($email)), $name, $subject, $html, $text, \now()]);
    }
    public static function milestone(int $eventId, array $project): void
    {
        $stakeholders = DB::query('SELECT * FROM stakeholders ORDER BY id')->fetchAll();
        $recipients = [];
        foreach ($stakeholders as $person) {
            if (!filter_var($person['email'], FILTER_VALIDATE_EMAIL)) throw new ValidationException(['An administrator must configure all four stakeholder email addresses before milestones can be saved.']);
            $recipients[strtolower($person['email'])] = $person['full_name'];
        }
        $requester = DB::one('SELECT full_name, email FROM users WHERE id=?', [$project['requester_id']]);
        $recipients[strtolower($requester['email'])] = $requester['full_name'];
        $label = Projects::STATUSES[$project['status']];
        $subject = 'Crash analysis · ' . $project['rcoc_number'] . ' · ' . $label;
        $link = \app_url('/project.php?id=' . $project['id']);
        $text = $project['name'] . "\nRCOC project: " . $project['rcoc_number'] . "\nMDOT job: " . $project['mdot_number'] . "\nStatus: $label\nNeeded by: " . \local_date($project['needed_by']) . " (signed and complete)\nProject: $link";
        $html = '<h2>' . \h($project['name']) . '</h2><p><strong>' . \h($label) . '</strong></p><p>RCOC project: ' . \h($project['rcoc_number']) . '<br>MDOT job: ' . \h($project['mdot_number']) . '<br>Needed by: ' . \h(\local_date($project['needed_by'])) . ' (signed and complete)</p><p><a href="' . \h($link) . '">View project</a></p><p>Sign in to view project details.</p>';
        foreach ($recipients as $email => $name) self::queue($eventId, $email, $name, $subject, $html, $text);
    }
    public static function deliverPending(int $limit = 25): void
    {
        $ids = DB::query("SELECT id FROM mail_outbox WHERE state='pending' ORDER BY id LIMIT " . max(1, min(100, $limit)))->fetchAll();
        foreach ($ids as $row) self::deliver((int)$row['id']);
    }
    public static function deliver(int $id): bool
    {
        $row = DB::transaction(function () use ($id): ?array {
            $row = DB::one('SELECT * FROM mail_outbox WHERE id=?' . DB::lock(), [$id]);
            if (!$row || !in_array($row['state'], ['pending', 'failed'], true)) return null;
            DB::query("UPDATE mail_outbox SET state='sending', locked_at=?, attempts=attempts+1 WHERE id=?", [\now(), $id]);
            return $row;
        });
        if (!$row) return false;
        try {
            self::send($row);
        } catch (\Throwable $e) {
            // Keep SMTP diagnostics out of the browser and never log message bodies or reset tokens.
            error_log('Email ' . $id . ' delivery failed (' . get_class($e) . ').');
            DB::query("UPDATE mail_outbox SET state='failed', last_error=?, locked_at=NULL WHERE id=?", ['Delivery failed. Check SMTP configuration and connectivity, then retry.', $id]);
            return false;
        }
        try {
            DB::query("UPDATE mail_outbox SET state='sent', sent_at=?, last_error=NULL, locked_at=NULL WHERE id=?", [\now(), $id]);
            return true;
        } catch (\Throwable $e) {
            // SMTP accepted this message. Preserve the sending state so an unknown
            // outcome cannot be mistaken for a definitely failed delivery.
            error_log('Email ' . $id . ' was accepted but its delivery receipt could not be saved.');
            return false;
        }
    }
    public static function retry(int $actorId, int $id): bool
    {
        Auth::actor($actorId, ['admin']);
        $row = DB::one('SELECT * FROM mail_outbox WHERE id=?', [$id]);
        if (!$row) throw new ValidationException(['Email not found.']);
        if ($row['state'] === 'sending') {
            if ($row['locked_at'] > gmdate('Y-m-d H:i:s', time() - 600)) throw new ValidationException(['Delivery is still in progress. Wait before retrying.']);
            DB::query("UPDATE mail_outbox SET state='failed', last_error='Previous delivery outcome unknown.' WHERE id=? AND state='sending' AND locked_at<=?", [$id, gmdate('Y-m-d H:i:s', time() - 600)]);
        }
        return self::deliver($id);
    }
    private static function send(array $row): void
    {
        if (\env('APP_ENV') === 'test' && self::$testTransport) { (self::$testTransport)($row); return; }
        if (\env('MAIL_TRANSPORT', 'smtp') === 'log' && \env('APP_ENV') !== 'production') {
            file_put_contents(APP_ROOT . '/storage/mail.log', \now() . ' LOCAL ONLY #' . $row['id'] . ' ' . $row['recipient'] . ' ' . $row['subject'] . PHP_EOL, FILE_APPEND | LOCK_EX);
            return;
        }
        foreach (['SMTP_HOST', 'SMTP_FROM_EMAIL'] as $key) if (!\env($key)) throw new \RuntimeException('SMTP is not configured.');
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = \env('SMTP_HOST');
        $mail->Port = (int)\env('SMTP_PORT', '587');
        $mail->SMTPAuth = \env('SMTP_USER') !== '';
        $mail->Username = \env('SMTP_USER');
        $mail->Password = \env('SMTP_PASS');
        $encryption = \env('SMTP_ENCRYPTION', 'tls');
        if (!in_array($encryption, ['tls', 'ssl'], true)) throw new \RuntimeException('SMTP encryption must be tls or ssl.');
        $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 10;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(\env('SMTP_FROM_EMAIL'), \env('SMTP_FROM_NAME', 'RCOC Crash Analysis'));
        $mail->addAddress($row['recipient'], $row['recipient_name']);
        $host = parse_url(\env('APP_URL'), PHP_URL_HOST) ?: 'lap.local';
        $mail->MessageID = '<lap-' . $row['id'] . '-' . substr(hash('sha256', $row['created_at']), 0, 12) . '@' . $host . '>';
        $mail->Subject = $row['subject'];
        $mail->isHTML(true);
        $mail->Body = $row['html_body'];
        $mail->AltBody = $row['text_body'];
        $mail->send();
    }
}
