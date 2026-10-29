<?php
declare(strict_types=1);
namespace App\Services;

use PDO;
use RuntimeException;

final class UserService
{
    /**
     * Authenticate by email and password, OR by legacy single password.
     */
    public static function authenticate(PDO $db, string $identifier, string $password): ?array
    {
        $identifier = trim($identifier);
        $password = trim($password);
        if ($password === '') return null;

        // 1. Try finding in `users` table by email
        if ($identifier !== '' && filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(email) = ?");
            $stmt->execute([strtolower($identifier)]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && password_verify($password, $user['password_hash'])) {
                unset($user['password_hash']);
                return $user;
            }
        }

        // 2. If identifier is empty or not matched, check if any user has this password
        $stmt = $db->query("SELECT * FROM users ORDER BY (role = 'admin') DESC, id ASC");
        $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allUsers as $u) {
            if (password_verify($password, $u['password_hash'])) {
                // If identifier was given and matches this user's email, or if no identifier was given
                if ($identifier === '' || strtolower($identifier) === strtolower($u['email'])) {
                    unset($u['password_hash']);
                    return $u;
                }
            }
        }

        // 3. Fallback to legacy ADMIN_PASSWORD_HASH in environment
        $adminHash = getenv('ADMIN_PASSWORD_HASH') ?: '';
        if ($adminHash !== '' && password_verify($password, $adminHash)) {
            return [
                'id' => 1,
                'name' => 'Administrator',
                'email' => getenv('ADMIN_EMAIL') ?: 'admin@rslsolution.com',
                'role' => 'admin',
                'assigned_account_id' => 1
            ];
        }

        return null;
    }

    /**
     * List all users (passwords excluded).
     */
    public static function getAll(PDO $db): array
    {
        $stmt = $db->query("
            SELECT u.id, u.name, u.email, u.role, u.assigned_account_id, u.created_at, u.updated_at,
                   m.name as account_name, m.email as account_email
            FROM users u
            LEFT JOIN mail_accounts m ON u.assigned_account_id = m.id
            ORDER BY (u.role = 'admin') DESC, u.name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get user by ID.
     */
    public static function getById(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.role, u.assigned_account_id, u.created_at, u.updated_at,
                   m.name as account_name, m.email as account_email
            FROM users u
            LEFT JOIN mail_accounts m ON u.assigned_account_id = m.id
            WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create or update a team member.
     */
    public static function save(PDO $db, array $data, ?int $id = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $role = ($data['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $assignedAccountId = !empty($data['assigned_account_id']) ? (int) $data['assigned_account_id'] : null;
        $password = (string) ($data['password'] ?? '');
        $now = gmdate('c');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid name and email address are required.');
        }

        // Updating existing user
        if ($id !== null) {
            $existing = self::getById($db, $id);
            if (!$existing) throw new RuntimeException('User not found.');

            if ($password !== '') {
                if (strlen($password) < 6) throw new RuntimeException('Password must be at least 6 characters.');
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $db->prepare("
                    UPDATE users
                    SET name = ?, email = ?, password_hash = ?, role = ?, assigned_account_id = ?, updated_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $email, $hash, $role, $assignedAccountId, $now, $id]);
            } else {
                $stmt = $db->prepare("
                    UPDATE users
                    SET name = ?, email = ?, role = ?, assigned_account_id = ?, updated_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $email, $role, $assignedAccountId, $now, $id]);
            }
            return self::getById($db, $id);
        }

        // Creating new user
        if (strlen($password) < 6) {
            throw new RuntimeException('Password must be at least 6 characters.');
        }

        // Check if email already exists
        $check = $db->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            throw new RuntimeException('A user with this email address already exists.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $db->prepare("
            INSERT INTO users (name, email, password_hash, role, assigned_account_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $email, $hash, $role, $assignedAccountId, $now, $now]);
        $newId = (int) $db->lastInsertId();
        return self::getById($db, $newId);
    }

    /**
     * Delete a user.
     */
    public static function delete(PDO $db, int $id, int $currentUserId): void
    {
        if ($id === $currentUserId) {
            throw new RuntimeException('You cannot delete your own active administrator account.');
        }
        $existing = self::getById($db, $id);
        if (!$existing) throw new RuntimeException('User not found.');

        // Prevent deleting the last administrator
        if ($existing['role'] === 'admin') {
            $adminCount = (int) ($db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() ?: 0);
            if ($adminCount <= 1) {
                throw new RuntimeException('Cannot delete the only remaining administrator.');
            }
        }

        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    }
}

