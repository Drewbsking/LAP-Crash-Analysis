<?php
declare(strict_types=1);
namespace App;

use PHPMailer\PHPMailer\PHPMailer;

final class Mail
{
    /** A test-only transport; never configured by an HTTP request. */
    public static ?\Closure $testTransport = null;
    public static function queue(?int $eventId, string $email, string $name, string $subject, string $html, string $text): int
    {
        DB::query('INSERT INTO mail_outbox(event_id, recipient, recipient_name, subject, html_body, text_body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$eventId, strtolower(trim($email)), $name, $subject, $html, $text, \now()]);
        return (int)DB::conn()->lastInsertId();
    }
    private static function projectRecipients(array $project): array
    {
        return ProjectContacts::recipients($project);
    }
    public static function milestone(int $eventId, array $project): void
    {
        $recipients = self::projectRecipients($project);
        $label = Projects::STATUSES[$project['status']];
        $subject = 'Crash analysis · ' . $project['rcoc_number'] . ' · ' . $label;
        $link = \app_url('/project.php?id=' . $project['id']);
        $text = $project['name'] . "\nRCOC project: " . $project['rcoc_number'] . "\nMDOT job: " . $project['mdot_number'] . "\nStatus: $label\nNeeded by: " . \local_date($project['needed_by']) . " (signed and complete)\nProject: $link";
        $html = '<h2>' . \h($project['name']) . '</h2><p><strong>' . \h($label) . '</strong></p><p>RCOC project: ' . \h($project['rcoc_number']) . '<br>MDOT job: ' . \h($project['mdot_number']) . '<br>Needed by: ' . \h(\local_date($project['needed_by'])) . ' (signed and complete)</p><p><a href="' . \h($link) . '">View project</a></p><p>Sign in to view project details.</p>';
        foreach ($recipients as $email => $name) self::queue($eventId, $email, $name, $subject, $html, $text);
    }
    /** Reminder records and recipient messages are committed by Reminders together. */
    public static function reminder(array $project, \DateTimeImmutable $asOf): array
    {
        $recipients = self::projectRecipients($project);
        $label = Projects::STATUSES[$project['status']];
        $submitted = \local_time($project['created_at']);
        $needed = \local_date($project['needed_by']);
        $localDay = $asOf->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d');
        $overdue = Projects::overdue($project, $localDay) ? 'Overdue - awaiting signed completion.' : '';
        $link = \app_url('/project.php?id=' . $project['id']);
        $subject = 'Crash analysis reminder · ' . $project['rcoc_number'] . ' · ' . $label;
        $text = "Two-week status reminder\n\n" . $project['name'] . "\nRCOC project: " . $project['rcoc_number']
            . "\nMDOT job: " . $project['mdot_number'] . "\nStatus: $label\nSubmitted: $submitted\nNeeded by: $needed (signed and complete)\n"
            . ($overdue ? "$overdue\n" : '') . "\nView current status: $link\n\n"
            . 'Status reminders are sent every two weeks from submission until the project is marked Complete. Sign in to view project details.';
        $html = '<h2>Two-week status reminder</h2><h3>' . \h($project['name']) . '</h3><p><strong>' . \h($label) . '</strong></p>'
            . '<p>RCOC project: ' . \h($project['rcoc_number']) . '<br>MDOT job: ' . \h($project['mdot_number'])
            . '<br>Submitted: ' . \h($submitted) . '<br>Needed by: ' . \h($needed) . ' (signed and complete)</p>'
            . ($overdue ? '<p><strong>' . \h($overdue) . '</strong></p>' : '')
            . '<p><a href="' . \h($link) . '">View current project status</a></p>'
            . '<p>Status reminders are sent every two weeks from submission until the project is marked Complete. Sign in to view project details.</p>';
        $ids = [];
        foreach ($recipients as $email => $name) $ids[] = self::queue(null, $email, $name, $subject, $html, $text);
        return $ids;
    }
    public static function deliverPending(int $limit = 25): void
    {
        $ids = DB::query("SELECT id FROM mail_outbox WHERE state='pending' ORDER BY id LIMIT " . max(1, min(100, $limit)))->fetchAll();
        foreach ($ids as $row) self::deliver((int)$row['id']);
    }
    public static function deliver(int $id): bool
    {
        $reminderProjectId = Reminders::projectForMail($id);
        $milestone = DB::one('SELECT e.project_id FROM mail_outbox m JOIN project_events e ON e.id=m.event_id WHERE m.id=?', [$id]);
        $projectId = $reminderProjectId ?? ($milestone ? (int)$milestone['project_id'] : null);
        $row = DB::transaction(function () use ($id, $projectId, $reminderProjectId): ?array {
            // Match completion's project-then-outbox lock order.
            $project = $projectId === null ? null : DB::one('SELECT * FROM projects WHERE id=?' . DB::lock(), [$projectId]);
            $row = DB::one('SELECT * FROM mail_outbox WHERE id=?' . DB::lock(), [$id]);
            if (!$row || !in_array($row['state'], ['pending', 'failed'], true)) return null;
            if ($reminderProjectId !== null && $project && $project['status'] === 'complete') {
                DB::query("UPDATE mail_outbox SET state='cancelled', last_error='Project complete; reminder cancelled.', locked_at=NULL WHERE id=?", [$id]);
                return null;
            }
            if ($project) {
                try {
                    $recipients = self::projectRecipients($project);
                } catch (ValidationException $e) {
                    DB::query("UPDATE mail_outbox SET state='failed', last_error='Review the project contacts and account email addresses in People.', locked_at=NULL WHERE id=?", [$id]);
                    return null;
                }
                if (!isset($recipients[strtolower(trim($row['recipient']))])) {
                    DB::query("UPDATE mail_outbox SET state='cancelled', last_error='Recipient is no longer assigned to this project.', locked_at=NULL WHERE id=?", [$id]);
                    return null;
                }
            }
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
