<?php
namespace Latra\Application\Security;

use Closure;
use Throwable;

final class RecordAuditEntry
{
    private Closure $failure;
    public function __construct(private AuthRepository $repository, callable $failure) { $this->failure = Closure::fromCallable($failure); }
    public function record(string $action, string $target, string $detail, array $actor, string $ip, mixed $before = null, mixed $after = null): void
    {
        try {
            $this->repository->appendAudit([
                'user_id'=>$actor['uid'] ?: null, 'username'=>$actor['username'] ?: null,
                'action'=>$action, 'target'=>$target, 'detail'=>$detail !== '' ? substr($detail,0,2000) : null,
                'ip'=>$ip, 'before_json'=>$before !== null ? json_encode($before) : null,
                'after_json'=>$after !== null ? json_encode($after) : null,
            ]);
        } catch (Throwable $error) {
            ($this->failure)('audit write skipped: ' . $error->getMessage());
        }
    }
}
