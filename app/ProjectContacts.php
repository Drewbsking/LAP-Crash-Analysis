<?php
declare(strict_types=1);
namespace App;

final class ProjectContacts
{
    public const SLOTS = ['analyst' => 'Traffic Safety contact', 'admin' => 'Review contact'];
    private const NAMES = ['analyst' => 'Jeremy Miller', 'admin' => 'Andrew Bates'];

    public static function installed(): bool
    {
        return (bool)(DB::sqlite()
            ? DB::one("SELECT name FROM sqlite_master WHERE type='table' AND name='project_contacts'")
            : DB::one("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='project_contacts'"));
    }

    /** Resolve two individual accounts, never everyone with the same role. */
    public static function reviewers(bool $rememberDefaults = false): array
    {
        $installed = self::installed();
        $selected = $installed ? DB::query('SELECT * FROM project_contacts')->fetchAll() : [];
        $ids = array_column($selected, 'user_id', 'slot');
        $reviewers = [];
        foreach (self::SLOTS as $slot => $label) {
            $candidates = DB::query('SELECT id, full_name, email, role, is_active FROM users WHERE role=? AND is_active=1 ORDER BY id', [$slot])->fetchAll();
            $person = null;
            if (!empty($ids[$slot])) {
                foreach ($candidates as $candidate) if ((int)$candidate['id'] === (int)$ids[$slot]) $person = $candidate;
            } else {
                $named = array_values(array_filter($candidates, fn($u) => strcasecmp(trim($u['full_name']), self::NAMES[$slot]) === 0));
                if (count($named) === 1) $person = $named[0];
                elseif (count($candidates) === 1) $person = $candidates[0];
                if ($person && $installed && $rememberDefaults) {
                    DB::query('UPDATE project_contacts SET user_id=? WHERE slot=? AND user_id IS NULL', [$person['id'], $slot]);
                }
            }
            $reviewers[$slot] = $person;
        }
        return $reviewers;
    }

    public static function ready(): bool
    {
        $reviewers = self::reviewers();
        return $reviewers['analyst'] !== null && $reviewers['admin'] !== null;
    }

    public static function recipients(array $project): array
    {
        $reviewers = self::reviewers(true);
        foreach ($reviewers as $person) {
            if (!$person) throw new ValidationException(['Select an active Traffic Safety contact and Review contact in People before sending project notifications.']);
        }
        $requester = DB::one('SELECT id, full_name, email FROM users WHERE id=?', [$project['requester_id']]);
        if (!$requester) throw new ValidationException(['The project requester account is missing.']);
        $recipients = [];
        foreach ([...array_values($reviewers), $requester] as $person) {
            $email = strtolower(trim($person['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ValidationException(['Update the project contact or requester email address in People.']);
            $recipients[$email] = $person['full_name'];
        }
        return $recipients;
    }

    public static function save(int $actorId, array $input): void
    {
        if (!self::installed()) throw new ValidationException(['Run php bin/console.php migrate before saving project contacts.']);
        DB::transaction(function () use ($actorId, $input): void {
            Auth::actor($actorId, ['admin']);
            DB::query('SELECT slot FROM project_contacts ORDER BY slot' . DB::lock())->fetchAll();
            $ids = [];
            foreach (self::SLOTS as $slot => $label) {
                $id = filter_var($input['contact_' . $slot] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $person = $id ? DB::one('SELECT id FROM users WHERE id=? AND role=? AND is_active=1', [$id, $slot]) : null;
                if (!$person) throw new ValidationException(['Select an active account for ' . $label . '.']);
                $ids[$slot] = $id;
            }
            foreach ($ids as $slot => $id) DB::query('UPDATE project_contacts SET user_id=? WHERE slot=?', [$id, $slot]);
        });
    }
}
