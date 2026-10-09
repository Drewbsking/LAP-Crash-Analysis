<?php
declare(strict_types=1);
namespace App;

final class Projects
{
    public const STATUSES = ['entered' => 'Entered', 'started' => 'Started', 'ready_for_review' => 'Ready for Review', 'complete' => 'Complete'];
    public const TYPES = ['4R', '3R', 'Preventive Maintenance', 'Safety', 'Other'];
    public const SAFETY = ['Realignment', 'Turn lanes', 'Shoulder widening', 'New signage', 'Signal upgrades', 'Pavement markings', 'Delineation', 'Guardrail updates', 'Removal of fixed objects', 'Other', 'None'];
    public const FIELDS = [
        'rcoc_number' => 'RCOC project number', 'name' => 'Project name', 'needed_by' => 'Needed-by date',
        'bid_date' => 'Bid letting date', 'design_exception' => 'Design exception', 'exception_details' => 'Design exception details',
        'project_type' => 'Project type', 'type_details' => 'Other project type', 'construction_year' => 'Construction year',
        'scope' => 'Project scope', 'limits_text' => 'Project limits', 'mdot_number' => 'MDOT job number',
        'safety_improvements' => 'Safety improvements', 'safety_details' => 'Safety improvement details', 'folder_path' => 'Analysis folder',
    ];
    public static function validate(array $input): array
    {
        $data = [];
        foreach (array_keys(self::FIELDS) as $key) {
            if (in_array($key, ['folder_path', 'safety_improvements'], true)) continue;
            $data[$key] = trim(is_string($input[$key] ?? null) ? $input[$key] : (is_int($input[$key] ?? null) ? (string)$input[$key] : ''));
        }
        $errors = [];
        foreach (['rcoc_number' => 100, 'name' => 200, 'scope' => 10000, 'limits_text' => 3000, 'mdot_number' => 100] as $key => $max) {
            if ($data[$key] === '' || mb_strlen($data[$key]) > $max) $errors[] = self::FIELDS[$key] . " is required and must be no longer than $max characters.";
        }
        $data['rcoc_number'] = mb_strtoupper($data['rcoc_number']);
        foreach (['needed_by', 'bid_date'] as $key) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data[$key]);
            if (!$date || $date->format('Y-m-d') !== $data[$key] || $data[$key] < '1900-01-01' || $data[$key] > '2200-12-31') $errors[] = 'Enter a valid ' . strtolower(self::FIELDS[$key]) . '.';
        }
        if (!in_array($data['design_exception'], ['0', '1'], true)) $errors[] = 'Select Yes or No for design exception.';
        if ($data['design_exception'] === '1' && !$data['exception_details']) $errors[] = 'Describe the design exception.';
        if ($data['design_exception'] !== '1') $data['exception_details'] = '';
        if (!in_array($data['project_type'], self::TYPES, true)) $errors[] = 'Select a project type.';
        if ($data['project_type'] === 'Other' && !$data['type_details']) $errors[] = 'Describe the other project type.';
        if ($data['project_type'] !== 'Other') $data['type_details'] = '';
        if (!preg_match('/^\d{4}$/', $data['construction_year']) || (int)$data['construction_year'] < 1900 || (int)$data['construction_year'] > 2200) $errors[] = 'Enter a construction year between 1900 and 2200.';
        $data['construction_year'] = (int)$data['construction_year'];
        $selection = $input['safety_improvements'] ?? [];
        if (!is_array($selection)) $selection = [];
        $selection = array_values(array_unique(array_filter($selection, 'is_string')));
        if (!$selection || array_diff($selection, self::SAFETY)) $errors[] = 'Select proposed safety improvements, or select None.';
        if (in_array('None', $selection, true) && count($selection) > 1) $errors[] = 'Select None by itself, or select the proposed safety improvements.';
        if (in_array('Other', $selection, true) && !$data['safety_details']) $errors[] = 'Describe the other safety improvements.';
        if (in_array('None', $selection, true)) $data['safety_details'] = '';
        foreach (['exception_details', 'type_details', 'safety_details'] as $key) if (mb_strlen($data[$key]) > 5000) $errors[] = self::FIELDS[$key] . ' must be no longer than 5,000 characters.';
        $data['safety_improvements'] = json_encode(array_values(array_intersect(self::SAFETY, $selection)), JSON_THROW_ON_ERROR);
        if ($errors) throw new ValidationException($errors);
        return $data;
    }
    public static function get(int $id): ?array
    {
        return DB::one('SELECT p.*, u.full_name AS requester_name FROM projects p JOIN users u ON u.id=p.requester_id WHERE p.id=?', [$id]);
    }
    public static function canEdit(array $user, array $project): bool
    {
        return $project['status'] !== 'complete' && ($user['role'] === 'admin' || (int)$project['requester_id'] === (int)$user['id']);
    }
    public static function canReview(array $user): bool { return in_array($user['role'], ['analyst', 'admin'], true); }
    public static function overdue(array $project, ?string $day = null): bool { return $project['status'] !== 'complete' && $project['needed_by'] < ($day ?? \today()); }
    public static function create(int $actorId, array $input, string $token): int
    {
        $actor = Auth::actor($actorId, ['design', 'admin']);
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new ValidationException(['Refresh the form and submit again.']);
        $data = self::validate($input);
        try {
            return DB::transaction(function () use ($actorId, $data, $token): int {
                Auth::actor($actorId, ['design', 'admin']);
                $replay = DB::one('SELECT id, requester_id FROM projects WHERE submission_token=?', [$token]);
                if ($replay) {
                    if ((int)$replay['requester_id'] !== $actorId) throw new ValidationException(['Refresh the form and submit again.']);
                    return (int)$replay['id'];
                }
                $columns = array_keys($data);
                $params = array_values($data);
                $columns = [...$columns, 'requester_id', 'status', 'submission_token', 'created_at', 'updated_at'];
                $params = [...$params, $actorId, 'entered', $token, \now(), \now()];
                DB::query('INSERT INTO projects(' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($params), '?')) . ')', $params);
                $id = (int)DB::conn()->lastInsertId();
                $project = self::get($id);
                $eventId = self::event($id, $actorId, 'milestone', null, 'entered', []);
                Mail::milestone($eventId, $project);
                return $id;
            });
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                $replay = DB::one('SELECT id, requester_id FROM projects WHERE submission_token=?', [$token]);
                if ($replay && (int)$replay['requester_id'] === $actorId) return (int)$replay['id'];
                self::duplicate($data['rcoc_number']);
            }
            throw $e;
        }
    }
    private static function duplicate(string $number): void
    {
        $existing = DB::one('SELECT id FROM projects WHERE rcoc_number=?', [$number]);
        if ($existing) throw new ValidationException(['A crash analysis already exists for RCOC project ' . $number . '.'], (int)$existing['id']);
    }
    private static function locked(int $id, int $version): array
    {
        $project = DB::one('SELECT * FROM projects WHERE id=?' . DB::lock(), [$id]);
        if (!$project) throw new ValidationException(['Project not found.']);
        if ($project['status'] === 'complete') throw new ValidationException(['This project is complete and read-only.']);
        if ((int)$project['version'] !== $version) throw new ValidationException(['This project changed while you were viewing it. Refresh the page before trying again.']);
        return $project;
    }
    public static function update(int $actorId, int $id, int $version, array $input): void
    {
        $data = self::validate($input);
        try {
            DB::transaction(function () use ($actorId, $id, $version, $data): void {
                $actor = Auth::actor($actorId);
                $project = self::locked($id, $version);
                if (!self::canEdit($actor, $project)) throw new ValidationException(['Only the requester or an administrator can edit this project.']);
                $changes = [];
                foreach ($data as $key => $value) if ((string)$value !== (string)$project[$key]) $changes[$key] = ['before' => $project[$key], 'after' => $value];
                if (!$changes) return;
                $assignments = array_map(fn($key) => "$key=?", array_keys($data));
                DB::query('UPDATE projects SET ' . implode(', ', $assignments) . ', version=version+1, updated_at=? WHERE id=?', [...array_values($data), \now(), $id]);
                self::event($id, $actorId, 'details_updated', $project['status'], $project['status'], $changes);
            });
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) self::duplicate($data['rcoc_number']);
            throw $e;
        }
    }
    public static function folder(int $actorId, int $id, int $version, string $folder): void
    {
        $folder = trim($folder);
        if (!$folder || mb_strlen($folder) > 1000 || preg_match('/[\x00-\x1F]/', $folder) || !(preg_match('/^[a-zA-Z]:\\\\/', $folder) || str_starts_with($folder, '\\\\'))) throw new ValidationException(['Enter a Windows folder path, for example G:\\A TOC\\Project folder (up to 1,000 characters).']);
        DB::transaction(function () use ($actorId, $id, $version, $folder): void {
            Auth::actor($actorId, ['analyst', 'admin']);
            $project = self::locked($id, $version);
            if ($folder === $project['folder_path']) return;
            $status = $project['status'] === 'entered' ? 'started' : $project['status'];
            DB::query('UPDATE projects SET folder_path=?, status=?, version=version+1, updated_at=? WHERE id=?', [$folder, $status, \now(), $id]);
            $event = self::event($id, $actorId, $status !== $project['status'] ? 'milestone' : 'folder_updated', $project['status'], $status, ['folder_path' => ['before' => $project['folder_path'], 'after' => $folder]]);
            if ($status !== $project['status']) Mail::milestone($event, self::get($id));
        });
    }
    public static function advance(int $actorId, int $id, int $version, string $target, bool $confirmed): void
    {
        if (!$confirmed) throw new ValidationException(['Check the confirmation box before saving this milestone.']);
        DB::transaction(function () use ($actorId, $id, $version, $target): void {
            Auth::actor($actorId, ['analyst', 'admin']);
            $project = self::locked($id, $version);
            $next = ['started' => 'ready_for_review', 'ready_for_review' => 'complete'];
            if (($next[$project['status']] ?? null) !== $target || !$project['folder_path']) throw new ValidationException(['Milestones must be completed in order.']);
            DB::query('UPDATE projects SET status=?, version=version+1, updated_at=? WHERE id=?', [$target, \now(), $id]);
            $event = self::event($id, $actorId, 'milestone', $project['status'], $target, []);
            Mail::milestone($event, self::get($id));
        });
    }
    private static function event(int $id, int $actorId, string $type, ?string $from, ?string $to, array $changes): int
    {
        DB::query('INSERT INTO project_events(project_id, actor_id, event_type, from_status, to_status, changes_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $actorId, $type, $from, $to, json_encode($changes, JSON_THROW_ON_ERROR), \now()]);
        return (int)DB::conn()->lastInsertId();
    }
    public static function search(array $filters): array
    {
        $where = []; $params = [];
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : 'active';
        if ($status === 'active' || (!isset(self::STATUSES[$status]) && $status !== 'all')) $where[] = "p.status <> 'complete'";
        elseif (isset(self::STATUSES[$status])) { $where[] = 'p.status=?'; $params[] = $status; }
        $year = is_string($filters['year'] ?? null) ? $filters['year'] : '';
        if (preg_match('/^\d{4}$/', $year)) { $where[] = 'p.construction_year=?'; $params[] = (int)$year; }
        $q = trim(is_string($filters['q'] ?? null) ? $filters['q'] : '');
        if ($q !== '') {
            $where[] = "(p.name LIKE ? ESCAPE '!' OR p.rcoc_number LIKE ? ESCAPE '!' OR p.mdot_number LIKE ? ESCAPE '!')";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($q, 0, 200)) . '%';
            $params = [...$params, $like, $like, $like];
        }
        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = (int)DB::query('SELECT COUNT(*) FROM projects p' . $clause, $params)->fetchColumn();
        $pages = max(1, (int)ceil($count / 25));
        $page = max(1, min($pages, (int)($filters['page'] ?? 1)));
        $rows = DB::query('SELECT p.*, u.full_name AS requester_name FROM projects p JOIN users u ON u.id=p.requester_id' . $clause . ' ORDER BY p.needed_by, p.id LIMIT 25 OFFSET ' . (($page - 1) * 25), $params)->fetchAll();
        return compact('rows', 'count', 'pages', 'page');
    }
}
