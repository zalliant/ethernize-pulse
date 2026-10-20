<?php

declare(strict_types=1);

namespace Ethernize\Pulse;

use Ethernize\Pulse\Exception\ValidationFailed;
use Ethernize\Pulse\Validation\Validator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Base class of reactive components.
 *
 * State lives in public properties marked #[State] or #[Input]; browser
 * callable methods are marked #[Action]. Everything else is server-only and
 * unreachable from the client. Rebuild server-only data (services, database
 * rows) in booted(), which runs on every request before the action.
 *
 * Optional hooks, all server-side:
 *   mount(...)        called once when the component is first rendered; it may declare
 *                     parameters, which receive the properties given in the template tag
 *   booted()          runs after state is restored, before an action
 *   with(): array     extra variables for the template, never serialized
 *   template(): string  Weave template name (default "pulse/<component-name>")
 *   authorize(string $action, array $arguments): bool  called before every action
 */
abstract class Component
{
    /**
     * Validation messages by field, rebuilt by every request. Exposed to the
     * template as "errors". Never part of the snapshot.
     *
     * @var array<string, list<string>>
     */
    public array $errors = [];

    private string $pulseId = '';
    private string $pulseName = '';
    private ?ServerRequestInterface $pulseRequest = null;
    private ?string $pulseActionKey = null;

    protected function booted(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    protected function with(): array
    {
        return [];
    }

    protected function template(): string
    {
        return 'pulse/' . $this->pulseName;
    }

    /**
     * Called before every action. Return false to deny it. Use it for checks
     * that depend on the component's own data, such as ownership.
     *
     * @param list<bool|int|float|string|null> $arguments
     */
    public function authorize(string $action, array $arguments): bool
    {
        return true;
    }

    /**
     * The current HTTP request, when one is available (for the authenticated user, for instance).
     */
    protected function request(): ?ServerRequestInterface
    {
        return $this->pulseRequest;
    }

    /**
     * Validates state properties and returns the valid values. On failure the
     * messages are stored in $errors and the action stops; the component is
     * rendered again with the errors and the values the user typed.
     *
     * @param array<string, string|list<string>> $rules property name => rules
     * @return array<string, mixed>
     * @throws ValidationFailed
     */
    protected function validate(array $rules): array
    {
        $values = ComponentMetadata::for(static::class)->export($this);
        [$clean, $errors] = Validator::validate($values, $rules);
        $this->errors = $errors;

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $clean;
    }

    protected function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * A random identifier the browser made for this call of the action, or null when the action runs
     * without one. Every call from the runtime has its own.
     *
     * What Pulse guarantees is that a snapshot is accepted once: the same request cannot run an action
     * twice. It does not guarantee that an operation happens exactly once. If a response is lost and the
     * user acts again, that is a new call with a new snapshot. An action that must have its effect at
     * most once (a payment, an e-mail) needs a key of your own that survives that, such as an order
     * number written under a unique constraint in the same transaction as the effect. Use this value as
     * a correlation id in logs, or as part of such a key when one call may be repeated by your own code.
     */
    protected function actionKey(): ?string
    {
        return $this->pulseActionKey;
    }
    /**
     * @internal
     */
    final public function pulseUseActionKey(?string $key): void
    {
        $this->pulseActionKey = $key;
    }

    /**
     * @internal
     */
    final public function pulseAttach(string $id, string $name, ?ServerRequestInterface $request): void
    {
        $this->pulseId = $id;
        $this->pulseName = $name;
        $this->pulseRequest = $request;
    }

    /**
     * @internal
     */
    final public function pulseId(): string
    {
        return $this->pulseId;
    }

    /**
     * @internal
     */
    final public function pulseName(): string
    {
        return $this->pulseName;
    }

    /**
     * @internal
     */
    final public function pulseBoot(): void
    {
        $this->booted();
    }

    /**
     * @internal
     */
    final public function pulseTemplate(): string
    {
        return $this->template();
    }

    /**
     * @internal
     * @return array<string, mixed>
     */
    final public function pulseWith(): array
    {
        return $this->with();
    }
}
