<?php
declare(strict_types=1);
namespace App;

use DateTimeImmutable;
use DateTimeZone;

final class Reminders
{
    public static function installed(): bool
    {
        $sql = DB::sqlite()
            ? "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('project_reminders','reminder_deliveries')"
            : "SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('project_reminders','reminder_deliveries')";
        return (int)DB::query($sql)->fetchColumn() === 2;
    }

    /** Latest due 14-day anniversary, preserving Eastern wall time through DST. */
    public static function duePeriod(string $submittedAt, DateTimeImmutable $asOf): ?array
    {
        $zone = new DateTimeZone('America/New_York');
        $submitted = (new DateTimeImmutable($submittedAt, new DateTimeZone('UTC')))->setTimezone($zone);
        $localNow = $asOf->setTimezone($zone);
        if ($localNow < $submitted) return null;
        $days = (int)$submitted->setTime(0, 0)->diff($localNow->setTime(0, 0))->format('%a');
        $period = intdiv($days, 14);
        $scheduled = $submitted->modify('+' . ($period * 14) . ' days');
        if ($scheduled > $localNow) {
            $period--;
            $scheduled = $submitted->modify('+' . ($period * 14) . ' days');
        }
        return $period < 1 ? null : [
            'period' => $period,
            'scheduled_for' => $scheduled->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    /** Queue once per due project; no delivery or project milestone changes here. */
    public static function queueDue(?DateTimeImmutable $asOf = null): array
    {
        if (!self::installed()) throw new \RuntimeException('Run php bin/console.php migrate to enable status reminders.');
        $asOf ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $queued = 0;
        $failed = 0;
        $lastId = 0;
        do {
            $projects = DB::query("SELECT id FROM projects WHERE status<>'complete' AND id>? ORDER BY id LIMIT 200", [$lastId])->fetchAll();
            foreach ($projects as $row) {
                $lastId = (int)$row['id'];
                try {
                    $queued += DB::transaction(function () use ($lastId, $asOf): int {
                        // Same first lock as workflow changes and reminder delivery.
                        $project = DB::one('SELECT * FROM projects WHERE id=?' . DB::lock(), [$lastId]);
                        if (!$project || $project['status'] === 'complete') return 0;
                        $due = self::duePeriod($project['created_at'], $asOf);
                        if (!$due) return 0;
                        $existing = DB::one('SELECT id FROM project_reminders WHERE project_id=? AND period_number>=? LIMIT 1' . DB::lock(), [$lastId, $due['period']]);
                        if ($existing) return 0;
                        DB::query('INSERT INTO project_reminders(project_id, period_number, scheduled_for, created_at) VALUES (?, ?, ?, ?)', [$lastId, $due['period'], $due['scheduled_for'], \now()]);
                        $reminderId = (int)DB::conn()->lastInsertId();
                        foreach (Mail::reminder($project, $asOf) as $mailId) {
                            DB::query('INSERT INTO reminder_deliveries(mail_id, reminder_id) VALUES (?, ?)', [$mailId, $reminderId]);
                        }
                        self::cancelPending($lastId, 'Superseded by a newer status reminder.', $due['period']);
                        return 1;
                    });
                } catch (ValidationException $e) {
                    // Leave the period unrecorded so a later run retries corrected addresses.
                    $failed++;
                    error_log('Status reminder could not be queued for project ' . $lastId . '. Review the project contacts and their account email addresses in People.');
                }
            }
        } while (count($projects) === 200);
        return compact('queued', 'failed');
    }

    /** Call while holding the project lock, in the same transaction as its change. */
    public static function cancelPending(int $projectId, string $reason = 'Project complete; reminder cancelled.', ?int $beforePeriod = null): void
    {
        if (!self::installed()) return;
        $periodClause = $beforePeriod === null ? '' : ' AND r.period_number<?';
        $params = [$reason, $projectId];
        if ($beforePeriod !== null) $params[] = $beforePeriod;
        DB::query("UPDATE mail_outbox SET state='cancelled', last_error=?, locked_at=NULL WHERE state IN ('pending','failed') AND id IN (SELECT d.mail_id FROM reminder_deliveries d JOIN project_reminders r ON r.id=d.reminder_id WHERE r.project_id=?" . $periodClause . ')', $params);
    }

    public static function projectForMail(int $mailId): ?int
    {
        if (!self::installed()) return null;
        $row = DB::one('SELECT r.project_id FROM reminder_deliveries d JOIN project_reminders r ON r.id=d.reminder_id WHERE d.mail_id=?', [$mailId]);
        return $row ? (int)$row['project_id'] : null;
    }
}
