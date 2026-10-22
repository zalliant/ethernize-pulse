<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Cli;

use Ethernize\Core\Cli\Generator\Fields;
use Ethernize\Core\Cli\Generator\Project;

/**
 * The code written by `make:pulse` and by the Pulse part of `make:resource`. Every component comes in two
 * shapes from the same text: one file with the class and the template (the default), or a class file and a
 * template file (--split).
 */
final class Stubs
{
    private function __construct()
    {
    }

    /**
     * A small stateful component to start from.
     *
     * @param list<string> $namespace folders below src/
     * @return array{class: string, template: string} the PHP part (without the closing tag) and the Weave template
     */
    public static function starter(Project $project, array $namespace, string $class): array
    {
        $ns = $project->namespaceFor($namespace);

        return [
            'class' => <<<PHP
                <?php

                declare(strict_types=1);

                namespace {$ns};

                use Ethernize\Pulse\Attribute\Action;
                use Ethernize\Pulse\Attribute\State;
                use Ethernize\Pulse\Component;

                final class {$class} extends Component
                {
                    #[State]
                    public int \$count = 0;

                    #[Action]
                    public function increment(): void
                    {
                        \$this->count++;
                    }
                }

                PHP,
            'template' => <<<'WEAVE'
                <div>
                    <p>Clicked {{ count }} times.</p>
                    <button type="button" pulse:click="increment">Click me</button>
                </div>

                WEAVE,
        ];
    }

    /**
     * A read-only list of a model with search and pages.
     *
     * @param list<string> $namespace
     * @param class-string|string $model fully qualified model class
     * @return array{class: string, template: string}
     */
    public static function list(Project $project, array $namespace, string $class, string $model, string $variable, string $plural, Fields $fields): array
    {
        $ns = $project->namespaceFor($namespace);
        $short = substr($model, (int) strrpos($model, '\\') + 1);
        $search = null;
        $columns = [];

        foreach ($fields->fields as $field) {
            if ($search === null && in_array($field['type'], ['string', 'text'], true)) {
                $search = $field['name'];
            }

            if ($field['type'] !== 'json' && count($columns) < 5) {
                $columns[] = $field['name'];
            }
        }

        $filter = $search === null ? '' : <<<PHP

                    if (\$this->search !== '') {
                        \$query->where('{$search}', 'LIKE', '%' . \$this->search . '%');
                    }

            PHP;
        $searchProperty = $search === null ? '' : <<<'PHP'

                #[Input(max: 100)]
                public string $search = '';
            PHP;
        $searchAction = $search === null ? '' : <<<'PHP'

                #[Action]
                public function search(): void
                {
                    $this->page = 1;
                }
            PHP;

        $head = implode('', array_map(static fn (string $name): string => '<th>' . ucfirst(str_replace('_', ' ', $name)) . '</th>', $columns));
        $cells = implode('', array_map(static fn (string $name): string => '<td>{{ ' . $variable . '.' . $name . ' }}</td>', $columns));
        $input = $search === null ? '' : "    <input type=\"search\" pulse:model=\"search\" pulse:input=\"search\" :value=\"search\" placeholder=\"Search\" aria-label=\"Search\">\n";
        $imports = $search === null ? "use Ethernize\\Pulse\\Attribute\\Action;\nuse Ethernize\\Pulse\\Attribute\\State;" : "use Ethernize\\Pulse\\Attribute\\Action;\nuse Ethernize\\Pulse\\Attribute\\Input;\nuse Ethernize\\Pulse\\Attribute\\State;";

        return [
            'class' => <<<PHP
                <?php

                declare(strict_types=1);

                namespace {$ns};

                {$imports}
                use Ethernize\Pulse\Component;
                use {$model};

                /**
                 * A read-only list. It has no authorization: add #[Authorize('ability')] to the class before showing
                 * data that not everyone may see.
                 */
                final class {$class} extends Component
                {
                    #[State]
                    public int \$page = 1;
                {$searchProperty}

                    /**
                     * Rebuilt on every request from the database: nothing here is stored in the browser.
                     */
                    protected function with(): array
                    {
                        \$query = {$short}::query()->orderBy('id');
                {$filter}
                        \$page = \$query->paginate(15, max(1, \$this->page));

                        return [
                            '{$plural}' => array_map(static fn ({$short} \$item): array => \$item->toArray(), \$page->items),
                            'pagination' => ['page' => \$page->currentPage, 'last' => \$page->lastPage(), 'total' => \$page->total, 'hasMore' => \$page->hasMore, 'hasPrevious' => \$page->hasPrevious()],
                        ];
                    }
                {$searchAction}

                    #[Action]
                    public function next(): void
                    {
                        \$this->page++;
                    }

                    #[Action]
                    public function previous(): void
                    {
                        \$this->page = max(1, \$this->page - 1);
                    }
                }

                PHP,
            'template' => <<<WEAVE
                <section>
                {$input}    <table>
                        <thead><tr>{$head}</tr></thead>
                        <tbody>
                @each ({$variable} in {$plural}, key: {$variable}.id)
                            <tr>{$cells}</tr>
                @end
                        </tbody>
                    </table>
                @if (pagination.total == 0)
                    <p>Nothing here yet.</p>
                @end
                    <nav>
                        <button type="button" pulse:click="previous" :disabled="!pagination.hasPrevious">Previous</button>
                        <span>Page {{ pagination.page }} of {{ pagination.last }}</span>
                        <button type="button" pulse:click="next" :disabled="!pagination.hasMore">Next</button>
                    </nav>
                </section>

                WEAVE,
        ];
    }

    /**
     * The typed form class that a form component edits.
     *
     * @param list<string> $namespace
     */
    public static function formData(Project $project, array $namespace, string $class, Fields $fields): string
    {
        $ns = $project->namespaceFor($namespace);
        $properties = [];

        foreach ($fields->fields as $field) {
            if ($field['type'] === 'json') {
                $properties[] = '    // ' . $field['name'] . ' is JSON: edit it with a dedicated component, not a text box.';
                continue;
            }

            [$type, $default, $rules, $max] = self::formField($field);
            $attribute = $max === null ? sprintf("#[Field('%s')]", $rules) : sprintf("#[Field('%s', max: %d)]", $rules, $max);
            $properties[] = sprintf("    %s\n    public %s%s \$%s = %s;", $attribute, $field['nullable'] ? '?' : '', $type, $field['name'], $field['nullable'] ? 'null' : $default);
        }

        $body = implode("\n\n", $properties);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use Ethernize\Pulse\Attribute\Field;
            use Ethernize\Pulse\Form\Form;

            /**
             * The values the form edits. It is separate from the model on purpose: the browser can write these
             * fields and nothing else, and the model decides what it accepts when the values are saved.
             */
            final class {$class} extends Form
            {
            {$body}
            }

            PHP;
    }

    /**
     * A create/edit form component. Saving needs an ability, so nothing can be written until the
     * application has decided who may.
     *
     * @param list<string> $namespace
     * @return array{class: string, template: string}
     */
    public static function form(Project $project, array $namespace, string $class, string $model, string $formClass, string $formFqcn, string $ability, Fields $fields): array
    {
        $ns = $project->namespaceFor($namespace);
        $short = substr($model, (int) strrpos($model, '\\') + 1);
        $rows = [];

        foreach ($fields->fields as $field) {
            if ($field['type'] === 'json') {
                continue;
            }

            $name = $field['name'];
            $label = ucfirst(str_replace('_', ' ', $name));
            $control = match ($field['type']) {
                'text' => sprintf('<textarea pulse:model="form.%s">{{ form.%s }}</textarea>', $name, $name),
                'bool' => sprintf('<input type="checkbox" pulse:model="form.%s" :checked="form.%s">', $name, $name),
                'int', 'bigint' => sprintf('<input type="number" step="1" pulse:model="form.%s" :value="form.%s">', $name, $name),
                'float' => sprintf('<input type="number" step="any" pulse:model="form.%s" :value="form.%s">', $name, $name),
                'date' => sprintf('<input type="date" pulse:model="form.%s" :value="form.%s">', $name, $name),
                default => sprintf('<input type="text" pulse:model="form.%s" :value="form.%s">', $name, $name),
            };
            $rows[] = "    <div>\n        <label>{$label}\n            {$control}\n        </label>\n@each (message in errors.form.{$name})\n        <small role=\"alert\">{{ message }}</small>\n@end\n    </div>";
        }

        $body = implode("\n", $rows);

        return [
            'class' => <<<PHP
                <?php

                declare(strict_types=1);

                namespace {$ns};

                use Ethernize\Pulse\Attribute\Action;
                use Ethernize\Pulse\Attribute\Authorize;
                use Ethernize\Pulse\Attribute\Input;
                use Ethernize\Pulse\Attribute\State;
                use Ethernize\Pulse\Component;
                use {$formFqcn};
                use {$model};

                /**
                 * Creates a {$short}, or edits one when mounted with an id: <{$class} :id="5" />.
                 *
                 * Nothing is open by default. The class needs the ability '{$ability}' to be shown at all and the
                 * save action needs it again, so until your application defines who has it (a Shield policy, or
                 * your own ActionAuthorizer) every request is refused.
                 */
                #[Authorize('{$ability}')]
                final class {$class} extends Component
                {
                    #[Input]
                    public {$formClass} \$form;

                    /** The record being edited. State, not input: the browser cannot change it. */
                    #[State]
                    public ?int \$recordId = null;

                    #[State]
                    public bool \$saved = false;

                    public function mount(?int \$id = null): void
                    {
                        if (\$id !== null) {
                            \$this->form = {$formClass}::from({$short}::findOrFail(\$id));
                            \$this->recordId = \$id;
                        }
                    }

                    #[Action(authorize: '{$ability}')]
                    public function save(): void
                    {
                        \$values = \$this->validateForm('form');
                        \$record = \$this->recordId === null ? new {$short}() : {$short}::findOrFail(\$this->recordId);

                        // fill() only sets the properties the model marks #[Fillable].
                        \$record->fill(\$values)->save();

                        \$this->recordId = \$record->id;
                        \$this->saved = true;
                    }
                }

                PHP,
            'template' => <<<WEAVE
                <form pulse:submit="save">
                {$body}
                    <button type="submit">Save</button>
                @if (saved)
                    <p role="status">Saved.</p>
                @end
                </form>

                WEAVE,
        ];
    }

    /**
     * The whole text of a single-file component.
     *
     * @param array{class: string, template: string} $parts
     */
    public static function single(array $parts): string
    {
        return rtrim($parts['class']) . "\n?>\n" . $parts['template'];
    }

    /**
     * @param array{name: string, type: string, nullable: bool} $field
     * @return array{string, string, string, ?int} PHP type, default, rules, longest text
     */
    private static function formField(array $field): array
    {
        $required = $field['nullable'] ? 'nullable' : 'required';

        return match ($field['type']) {
            'string' => ['string', "''", $required . '|string|max:255', 255],
            'text' => ['string', "''", $required . '|string|max:10000', 10000],
            'int', 'bigint' => ['int', '0', $required . '|int', null],
            'float' => ['float', '0.0', $required . '|numeric', null],
            'bool' => ['bool', 'false', 'bool', null],
            'date' => ['string', "''", $required . '|date', 10],
            default => ['string', "''", $required . '|string|max:32', 32],
        };
    }
}
