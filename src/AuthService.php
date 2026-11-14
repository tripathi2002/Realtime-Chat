<?php

declare(strict_types=1);

final class AuthService
{
    private const SESSION_DAYS = 30;

    public function __construct(private readonly array $config)
    {
    }

    /**
     * @return array{ok: bool, user?: array<string, mixed>, authToken?: string, errors?: array<string, string>, message?: string}
     */
    public function signup(array $input): array
    {
        $email = $this->cleanEmail($input['email'] ?? '');
        $password = is_string($input['password'] ?? null) ? (string) $input['password'] : '';
        $displayName = $this->cleanName($input['displayName'] ?? $input['name'] ?? '');

        $errors = [];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required.';
        }
        if (mb_strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        if ($displayName === '' || mb_strlen($displayName) > 100) {
            $errors['displayName'] = 'Display name is required (max 100 chars).';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        try {
            $pdo = $this->pdo();
            $publicId = $this->newPublicId($pdo);
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO accounts (public_id, email, password_hash, display_name)
                 VALUES (:public_id, :email, :password_hash, :display_name)'
            );
            $stmt->execute([
                ':public_id' => $publicId,
                ':email' => $email,
                ':password_hash' => $hash,
                ':display_name' => $displayName,
            ]);

            $accountId = (int) $pdo->lastInsertId();
            $authToken = $this->createSession($pdo, $accountId);

            return [
                'ok' => true,
                'authToken' => $authToken,
                'user' => [
                    'publicId' => $publicId,
                    'email' => $email,
                    'displayName' => $displayName,
                ],
            ];
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'errors' => ['email' => 'An account with this email already exists.']];
            }
            error_log('[chat-api] signup: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to sign up right now.'];
        } catch (Throwable $e) {
            error_log('[chat-api] signup: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to sign up right now.'];
        }
    }

    /**
     * @return array{ok: bool, user?: array<string, mixed>, authToken?: string, errors?: array<string, string>, message?: string}
     */
    public function login(array $input): array
    {
        $email = $this->cleanEmail($input['email'] ?? '');
        $password = is_string($input['password'] ?? null) ? (string) $input['password'] : '';

        if ($email === '' || $password === '') {
            return ['ok' => false, 'errors' => ['email' => 'Email and password are required.']];
        }

        try {
            $pdo = $this->pdo();
            $stmt = $pdo->prepare(
                'SELECT id, public_id, email, password_hash, display_name
                 FROM accounts WHERE email = :email LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch();

            if (!is_array($row) || !password_verify($password, (string) $row['password_hash'])) {
                return ['ok' => false, 'message' => 'Invalid email or password.'];
            }

            $authToken = $this->createSession($pdo, (int) $row['id']);

            return [
                'ok' => true,
                'authToken' => $authToken,
                'user' => [
                    'publicId' => (string) $row['public_id'],
                    'email' => (string) $row['email'],
                    'displayName' => (string) $row['display_name'],
                ],
            ];
        } catch (Throwable $e) {
            error_log('[chat-api] login: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to log in right now.'];
        }
    }

    /**
     * @return array{ok: bool}
     */
    public function logout(string $authToken): array
    {
        try {
            $pdo = $this->pdo();
            $stmt = $pdo->prepare('DELETE FROM auth_sessions WHERE token = :token');
            $stmt->execute([':token' => $authToken]);
        } catch (Throwable $e) {
            error_log('[chat-api] logout: ' . $e->getMessage());
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, user?: array<string, mixed>, message?: string}
     */
    public function me(string $authToken): array
    {
        try {
            $account = $this->requireAccount($authToken);

            return [
                'ok' => true,
                'user' => [
                    'publicId' => (string) $account['public_id'],
                    'email' => (string) $account['email'],
                    'displayName' => (string) $account['display_name'],
                ],
            ];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[chat-api] me: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to load profile.'];
        }
    }

    /**
     * @return array{id: int, public_id: string, email: string, display_name: string}
     */
    public function requireAccount(string $authToken): array
    {
        if ($authToken === '' || !preg_match('/^[a-f0-9]{64}$/', $authToken)) {
            throw new InvalidArgumentException('Please sign in to continue.');
        }

        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            'SELECT a.id, a.public_id, a.email, a.display_name
             FROM auth_sessions s
             INNER JOIN accounts a ON a.id = s.account_id
             WHERE s.token = :token AND s.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':token' => $authToken]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new InvalidArgumentException('Please sign in to continue.');
        }

        return [
            'id' => (int) $row['id'],
            'public_id' => (string) $row['public_id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
        ];
    }

    private function pdo(): PDO
    {
        $pdo = Database::connection($this->config);
        Database::ensureChatTables($pdo);

        return $pdo;
    }

    private function createSession(PDO $pdo, int $accountId): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            'INSERT INTO auth_sessions (token, account_id, expires_at)
             VALUES (:token, :account_id, DATE_ADD(NOW(), INTERVAL ' . self::SESSION_DAYS . ' DAY))'
        );
        $stmt->execute([
            ':token' => $token,
            ':account_id' => $accountId,
        ]);

        return $token;
    }

    private function newPublicId(PDO $pdo): string
    {
        for ($i = 0; $i < 8; $i++) {
            $id = 'u_' . bin2hex(random_bytes(4));
            $stmt = $pdo->prepare('SELECT id FROM accounts WHERE public_id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                return $id;
            }
        }

        return 'u_' . bin2hex(random_bytes(8));
    }

    private function cleanEmail(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_strtolower(trim($value));
    }

    private function cleanName(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');

        return mb_substr($value, 0, 100);
    }
}
