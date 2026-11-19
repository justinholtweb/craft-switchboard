<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Rules;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Rule lookups. Enabled rules are read once per request (or job) and kept, since
 * element saves ask "is anyone listening for this?" on every save.
 */
class Rules
{
    /** @var Collection<int, Rule>|null */
    private ?Collection $enabled = null;

    /** @return Collection<int, Rule> */
    public function all(): Collection
    {
        return Rule::query()->orderBy('name')->get();
    }

    /** @return Collection<int, Rule> */
    public function enabled(): Collection
    {
        if ($this->enabled === null) {
            try {
                $this->enabled = Schema::hasTable('switchboard_rules')
                    ? Rule::query()->where('enabled', true)->orderBy('id')->get()
                    : new Collection;
            } catch (Throwable $e) {
                report($e);
                $this->enabled = new Collection;
            }
        }

        return $this->enabled;
    }

    /** @return Collection<int, Rule> */
    public function forTrigger(string $trigger): Collection
    {
        return $this->enabled()->filter(fn (Rule $rule) => $rule->trigger === $trigger)->values();
    }

    /** @param list<string> $triggers */
    public function listening(array $triggers): bool
    {
        return $this->enabled()->contains(fn (Rule $rule) => in_array($rule->trigger, $triggers, true));
    }

    public function get(int $id): ?Rule
    {
        return Rule::query()->find($id);
    }

    public function getByHandle(string $handle): ?Rule
    {
        return Rule::query()->where('handle', $handle)->first();
    }

    public function getByUid(string $uid): ?Rule
    {
        return Rule::query()->where('uid', $uid)->first();
    }

    public function forget(): void
    {
        $this->enabled = null;
    }
}
