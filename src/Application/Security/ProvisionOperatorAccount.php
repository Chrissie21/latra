<?php
declare(strict_types=1);

namespace Latra\Application\Security;

/**
 * Creates an account or resets its password.
 *
 * Callers choose whether the reset also restores administrative access
 * (`role`) and clears the lockout counters, matching install versus
 * admin-reset behaviour.
 */
final class ProvisionOperatorAccount
{
    public function __construct(
        private OperatorAccountRepository $repository,
        private PasswordHasher $hasher,
    ) {
    }

    /**
     * @param array{role?:string, display_name?:string, reset_lockout?:bool} $options
     * @return bool True when an existing account was updated.
     */
    public function provision(string $username, string $password, array $options = []): bool
    {
        $passwordHash = $this->hasher->hash($password);
        $existing = $this->repository->findIdByName($username);
        if ($existing !== null) {
            $fields = ['password_hash' => $passwordHash, 'is_active' => 1];
            if (isset($options['role'])) {
                $fields['role'] = $options['role'];
            }
            if (!empty($options['reset_lockout'])) {
                $fields['failed_logins'] = 0;
                $fields['locked_until'] = null;
            }
            $this->repository->update($existing, $fields);
            return true;
        }

        $account = [
            'username' => $username,
            'password_hash' => $passwordHash,
            'display_name' => $options['display_name'] ?? ucfirst($username),
            'is_active' => 1,
        ];
        if (isset($options['role'])) {
            $account['role'] = $options['role'];
        }
        $this->repository->create($account);
        return false;
    }
}
