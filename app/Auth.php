<?php
declare(strict_types=1);
namespace App;

final class Auth
{
    public const ROLES = ['design' => 'Design', 'analyst' => 'Traffic Safety analyst', 'admin' => 'Administrator'];
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        $user = DB::one('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']]);
        $expired = time() - (int)($_SESSION['last_activity'] ?? 0) > (int)\env('SESSION_MINUTES', '120') * 60;
        if (!$user || !$user['is_active'] || $expired || (int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
            self::logout();
            return null;
        }
        $_SESSION['last_activity'] = time();
        return $user;
    }
    public static function requireLogin(bool $allowReset = false): array
    {
        $user = self::user();
        if (!$user) \redirect('/login.php');
        if ($user['must_reset'] && !$allowReset) \redirect('/change-password.php');
        return $user;
    }
    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'admin') \abort_page(403, 'This page is available to administrators.');
        return $user;
    }
    public static function actor(int $id, array $roles = []): array
    {
        $user = DB::one('SELECT * FROM users WHERE id=?', [$id]);
        if (!$user || !$user['is_active'] || $user['must_reset'] || ($roles && !in_array($user['role'], $roles, true))) {
            throw new ValidationException(['Your account does not have permission for this action.']);
        }
        return $user;
    }
    public static function rateLimit(string $purpose, string $identity, int $limit, int $minutes = 15): bool
    {
        return DB::transaction(function () use ($purpose, $identity, $limit, $minutes): bool {
            $key = hash('sha256', $purpose . ':' . strtolower($identity));
            $insert = DB::sqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            DB::query("$insert INTO rate_limits(bucket, attempts, expires_at) VALUES (?, 0, ?)", [$key, gmdate('Y-m-d H:i:s', time() + $minutes * 60)]);
            $row = DB::one('SELECT * FROM rate_limits WHERE bucket=?' . DB::lock(), [$key]);
            if ($row['expires_at'] <= \now()) {
                DB::query('UPDATE rate_limits SET attempts=1, expires_at=? WHERE bucket=?', [gmdate('Y-m-d H:i:s', time() + $minutes * 60), $key]);
                return true;
            }
            if ((int)$row['attempts'] >= $limit) return false;
            DB::query('UPDATE rate_limits SET attempts=attempts+1 WHERE bucket=?', [$key]);
            return true;
        });
    }
    public static function login(string $username, string $password, string $ip): bool
    {
        if (!self::rateLimit('login-account', trim($username), 15) || !self::rateLimit('login-ip', $ip, 60)) return false;
        $user = DB::one('SELECT * FROM users WHERE username=?', [strtolower(trim($username))]);
        if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) return false;
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION = ['user_id' => (int)$user['id'], 'session_version' => (int)$user['session_version'], 'last_activity' => time(), 'csrf' => bin2hex(random_bytes(32))];
        return true;
    }
    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }
    public static function validatePassword(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 128) throw new ValidationException(['Use a password between 12 and 128 characters.']);
    }
    public static function hashPassword(string $password): string
    {
        self::validatePassword($password);
        return password_hash($password, PASSWORD_ARGON2ID);
    }
    public static function temporaryPassword(): string { return bin2hex(random_bytes(10)) . '!a'; }
    public static function createUser(array $input, string $temporaryPassword, ?int $actorId = null): int
    {
        if ($actorId !== null) self::actor($actorId, ['admin']);
        elseif (PHP_SAPI !== 'cli' || DB::one('SELECT id FROM users LIMIT 1')) throw new ValidationException(['Use an administrator account to create users.']);
        $values = self::validateUser($input);
        try {
            DB::query('INSERT INTO users(username, full_name, email, role, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [...array_values($values), self::hashPassword($temporaryPassword), \now(), \now()]);
            return (int)DB::conn()->lastInsertId();
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) throw new ValidationException(['That username or email address is already in use.']);
            throw $e;
        }
    }
    /** Create the account, setup token, and welcome notification together. Send after commit. */
    public static function inviteUser(array $input, int $actorId): array
    {
        return DB::transaction(function () use ($input, $actorId): array {
            $userId = self::createUser($input, self::temporaryPassword(), $actorId);
            $user = DB::one('SELECT * FROM users WHERE id=?', [$userId]);
            return ['user_id' => $userId, 'mail_id' => self::queueWelcome($user)];
        });
    }
    public static function resendWelcome(int $actorId, int $userId): int
    {
        return DB::transaction(function () use ($actorId, $userId): int {
            self::actor($actorId, ['admin']);
            $user = DB::one('SELECT * FROM users WHERE id=?' . DB::lock(), [$userId]);
            if (!$user || !$user['is_active'] || !$user['must_reset']) {
                throw new ValidationException(['Welcome emails are available only for active accounts that still need to set a password.']);
            }
            return self::queueWelcome($user);
        });
    }
    /** Call inside a transaction while the user's row is locked (or newly inserted). */
    private static function passwordLink(int $userId, int $lifetime): string
    {
        $token = bin2hex(random_bytes(32));
        DB::query('UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL', [\now(), $userId]);
        DB::query('INSERT INTO password_resets(user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)', [$userId, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + $lifetime), \now()]);
        return \app_url('/reset-password.php?token=' . $token);
    }
    private static function queueWelcome(array $user): int
    {
        $link = self::passwordLink((int)$user['id'], 86400);
        $login = \app_url('/login.php');
        $role = self::ROLES[$user['role']];
        $purpose = 'The LAP Crash Analysis Portal collects the required project information up front when a crash analysis is requested. It records that the request was made and lets staff see where it stands, from Entered to Started, Ready for Review, and Complete.';
        $html = '<h2>Welcome to RCOC Crash Analysis</h2><p>Hello ' . \h($user['full_name']) . ',</p>'
            . '<p>Your staff account is ready. Use the link below to choose your password.</p>'
            . '<h3>What the site is used for</h3><p>' . \h($purpose) . '</p>'
            . '<p>Design staff submit project details and deadlines. Traffic Safety staff record analysis progress. Everyone can see whether a request has been submitted and its current status.</p>'
            . '<p><strong>Username:</strong> ' . \h($user['username']) . '<br><strong>Role:</strong> ' . \h($role) . '</p>'
            . '<p><a href="' . \h($link) . '">Set your password</a></p>'
            . '<p>This link expires in 24 hours and can be used once. If a newer link is sent, use the newest one.</p>'
            . '<p>After setting your password, <a href="' . \h($login) . '">sign in to the portal</a>. If the link expires, choose Forgot your password? on the sign-in page to request a fresh link, or contact Andrew Bates.</p>';
        $text = 'Hello ' . $user['full_name'] . ",\n\nWelcome to RCOC Crash Analysis. Your staff account is ready.\n\n"
            . $purpose . "\n\nDesign staff submit project details and deadlines. Traffic Safety staff record analysis progress. Everyone can see whether a request has been submitted and its current status.\n\n"
            . 'Username: ' . $user['username'] . "\nRole: $role\n\nSet your password: $link\n"
            . "This link expires in 24 hours and can be used once. If a newer link is sent, use the newest one.\n\n"
            . "After setting your password, sign in: $login\nIf the link expires, use Forgot your password? on the sign-in page or contact Andrew Bates.\n";
        Mail::queue(null, $user['email'], $user['full_name'], 'Welcome to RCOC Crash Analysis - set your password', $html, $text);
        return (int)DB::conn()->lastInsertId();
    }
    private static function validateUser(array $input): array
    {
        $values = [];
        foreach (['username', 'full_name', 'email', 'role'] as $key) $values[$key] = trim(is_string($input[$key] ?? null) ? $input[$key] : '');
        $values['username'] = strtolower($values['username']);
        $values['email'] = strtolower($values['email']);
        $errors = [];
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $values['username'])) $errors[] = 'Username must be 3–100 letters, numbers, periods, underscores, or hyphens.';
        if (!$values['full_name'] || mb_strlen($values['full_name']) > 191) $errors[] = 'Enter a full name of up to 191 characters.';
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 191) $errors[] = 'Enter a valid email address.';
        if (!isset(self::ROLES[$values['role']])) $errors[] = 'Select a valid role.';
        if ($errors) throw new ValidationException($errors);
        return $values;
    }
    public static function updateUser(int $actorId, int $userId, array $input): void
    {
        $values = self::validateUser($input);
        $active = !empty($input['is_active']) ? 1 : 0;
        DB::transaction(function () use ($actorId, $userId, $values, $active): void {
            // Serialize administrative edits so two admins cannot deactivate each other concurrently.
            $all = DB::query('SELECT * FROM users ORDER BY id' . DB::lock())->fetchAll();
            self::actor($actorId, ['admin']);
            $target = null;
            $remainingAdmins = 0;
            foreach ($all as $u) {
                if ((int)$u['id'] === $userId) $target = $u;
                elseif ($u['role'] === 'admin' && $u['is_active']) $remainingAdmins++;
            }
            if (!$target) throw new ValidationException(['User not found.']);
            if ($userId === $actorId && (!$active || $values['role'] !== 'admin')) throw new ValidationException(['You cannot deactivate or remove administrator access from your own account.']);
            if (!$remainingAdmins && (!$active || $values['role'] !== 'admin') && $target['role'] === 'admin') throw new ValidationException(['Keep at least one active administrator.']);
            try {
                $emailChanged = $target['email'] !== $values['email'];
                $revoke = ($target['role'] !== $values['role'] || (int)$target['is_active'] !== $active || $emailChanged) ? 1 : 0;
                DB::query('UPDATE users SET username=?, full_name=?, email=?, role=?, is_active=?, session_version=session_version+?, updated_at=? WHERE id=?', [...array_values($values), $active, $revoke, \now(), $userId]);
                if ($emailChanged || !$active) {
                    DB::query('UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL', [\now(), $userId]);
                }
            } catch (\PDOException $e) {
                if (str_starts_with((string)$e->getCode(), '23')) throw new ValidationException(['That username or email address is already in use.']);
                throw $e;
            }
        });
    }
    public static function adminReset(int $actorId, int $userId): string
    {
        return DB::transaction(function () use ($actorId, $userId): string {
            self::actor($actorId, ['admin']);
            if ($actorId === $userId) throw new ValidationException(['Use Change password to update your own password.']);
            if (!DB::one('SELECT id FROM users WHERE id=?' . DB::lock(), [$userId])) throw new ValidationException(['User not found.']);
            $password = self::temporaryPassword();
            self::replacePassword($userId, $password, true);
            return $password;
        });
    }
    private static function replacePassword(int $userId, string $password, bool $mustReset): void
    {
        DB::query('UPDATE users SET password_hash=?, must_reset=?, session_version=session_version+1, updated_at=? WHERE id=?', [self::hashPassword($password), (int)$mustReset, \now(), $userId]);
        DB::query('UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL', [\now(), $userId]);
    }
    public static function changePassword(int $userId, string $current, string $password): void
    {
        DB::transaction(function () use ($userId, $current, $password): void {
            $user = DB::one('SELECT * FROM users WHERE id=?' . DB::lock(), [$userId]);
            if (!$user || !$user['is_active'] || !password_verify($current, $user['password_hash'])) throw new ValidationException(['Your current password is incorrect.']);
            if (password_verify($password, $user['password_hash'])) throw new ValidationException(['Choose a new password different from your current password.']);
            self::replacePassword($userId, $password, false);
        });
        self::logout();
    }
    public static function requestReset(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        if (!self::rateLimit('reset-email', $email, 3, 60) || !self::rateLimit('reset-ip', $ip, 15, 60)) return;
        DB::transaction(function () use ($email): void {
            $user = DB::one('SELECT * FROM users WHERE email=? AND is_active=1' . DB::lock(), [$email]);
            if (!$user) return;
            $link = self::passwordLink((int)$user['id'], 3600);
            Mail::queue(null, $email, $user['full_name'], 'Reset your LAP Crash Analysis password', '<p>Hello ' . \h($user['full_name']) . ',</p><p><a href="' . \h($link) . '">Choose a new password</a>. This link expires in one hour and can be used once.</p><p>If you did not request this, you can ignore this email.</p>', "Reset your password: $link\nThis link expires in one hour and can be used once.");
        });
    }
    public static function resetWithToken(string $token, string $password): void
    {
        self::validatePassword($password);
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new ValidationException(['This reset link is invalid or expired. Request a new link.']);
        DB::transaction(function () use ($token, $password): void {
            // Lock the user first, matching invitation/resend/reset issuance, then
            // reread the token under lock so concurrent submissions cannot reuse it.
            $reset = DB::one('SELECT user_id FROM password_resets WHERE token_hash=?', [hash('sha256', $token)]);
            if (!$reset) throw new ValidationException(['This reset link is invalid or expired. Request a new link.']);
            $user = DB::one('SELECT * FROM users WHERE id=?' . DB::lock(), [$reset['user_id']]);
            if (!$user || !$user['is_active']) throw new ValidationException(['This reset link is invalid or expired. Request a new link.']);
            $reset = DB::one('SELECT * FROM password_resets WHERE token_hash=?' . DB::lock(), [hash('sha256', $token)]);
            if (!$reset || $reset['used_at'] || $reset['expires_at'] <= \now()) throw new ValidationException(['This reset link is invalid or expired. Request a new link.']);
            self::replacePassword((int)$user['id'], $password, false);
        });
        self::logout();
    }
}
