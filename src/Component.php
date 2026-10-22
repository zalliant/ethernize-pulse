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
     * Validation messages by field, rebuilt by every request. Exposed to the template as "errors". Never
     * part of the snapshot. A plain field has a list of messages; a Form property has a tree of them (see
     * Form::validate()): errors.form.name, errors.form.lines[0].qty.
     *
     * @var array<string, mixed>
     */
    public array $errors = [];

    private string $pulseId = '';
    private string $pulseName = '';
    private ?ServerRequestInterface $pulseRequest = null;
    private ?string $pulseActionKey = null;
    private ?Validator $pulseValidator = null;

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
     * Called after booted() every time the component is mounted, restored from a snapshot or refreshed,
     * before any input is applied or action runs. Return false to deny access to the component as a
     * whole (the response is 403). It is the place for checks that depend on the component's own data;
     * for a plain ability use #[Authorize('ability')] on the class. The snapshot is only proof that
     * Pulse issued the state; it never replaces this check.
     */
    protected function authorizeView(): bool
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
     * Rules are strings ("required|string|min:2") or lists that may hold rule objects and closures. Texts
     * come from the language set in pulse.locale; change one with $messages ("name.required" for a field,
     * "required" for every field) and the name shown for a field with $labels. validationMessages() and
     * validationLabels() give a component defaults for both.
     *
     * @param array<string, string|list<string|\Ethernize\Pulse\Validation\Rule|\Closure>> $rules property name => rules
     * @param array<string, string> $messages
     * @param array<string, string> $labels
     * @return array<string, mixed>
     * @throws ValidationFailed
     */
    protected function validate(array $rules, array $messages = [], array $labels = []): array
    {
        $values = ComponentMetadata::for(static::class)->export($this);
        [$clean, $errors] = ($this->pulseValidator ?? new Validator())->check($values, $rules, $messages + $this->validationMessages(), $labels + $this->validationLabels());
        $this->errors = $errors;

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $clean;
    }

    /**
     * Validates a Form property with the rules its class declares and returns its values. The messages go to
     * $errors under the property's name, as a tree.
     *
     * @param array<string, string> $messages
     * @param array<string, string> $labels
     * @return array<string, mixed>
     * @throws ValidationFailed
     */
    protected function validateForm(string $property, array $messages = [], array $labels = []): array
    {
        $form = ComponentMetadata::for(static::class)->state[$property]['type'] ?? null;

        if ($form !== 'form') {
            throw new \LogicException(sprintf('%s::$%s is not a Form property.', static::class, $property));
        }

        $this->errors = [];

        try {
            return (new \ReflectionProperty($this, $property))->getValue($this)->validate($this->pulseValidator ?? new Validator(), $messages + $this->validationMessages(), $labels + $this->validationLabels());
        } catch (ValidationFailed $failed) {
            $this->errors[$property] = $failed->errors;

            throw $failed;
        }
    }

    /**
     * Texts that replace the default validation messages of this component: "field.rule" or "rule" => text.
     *
     * @return array<string, string>
     */
    protected function validationMessages(): array
    {
        return [];
    }

    /**
     * How this component's fields are called in validation messages: field => label.
     *
     * @return array<string, string>
     */
    protected function validationLabels(): array
    {
        return [];
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
    final public function pulseUseValidator(Validator $validator): void
    {
        $this->pulseValidator = $validator;
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
    final public function pulseAuthorizeView(): bool
    {
        return $this->authorizeView();
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
