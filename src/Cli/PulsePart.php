<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Cli;

use Ethernize\Core\Cli\Arguments;
use Ethernize\Core\Cli\Generator\CoreParts;
use Ethernize\Core\Cli\Generator\Names;
use Ethernize\Core\Cli\Generator\Plan;
use Ethernize\Core\Cli\Generator\Project;
use Ethernize\Core\Cli\Generator\Resource;
use Ethernize\Core\Cli\Generator\ResourcePart;

/**
 * make:resource --pulse: a read-only list component (Products) and a create/edit form component (ProductForm)
 * with its typed form class (ProductData). The list needs the model of the feature; without one, only a
 * starter component is created. Nothing is shown on a page until you put the tag there, and the form refuses
 * every request until the application grants the ability it asks for.
 */
final class PulsePart implements ResourcePart
{
    /** @var list<array{string, list<string>}> components to register (split shape) */
    private array $registered = [];

    public function name(): string
    {
        return 'pulse';
    }

    public function isDefault(): bool
    {
        return true;
    }

    public function options(): array
    {
        return ['split'];
    }

    public function contribute(Resource $resource, Project $project, Plan $plan, Arguments $arguments): void
    {
        $split = $arguments->has('split');
        $folder = $resource->folder('Pulse');
        $model = CoreParts::model($resource, $project, $plan);
        $this->registered = [];

        if ($model === null) {
            ComponentFiles::add($plan, $project, $folder, $resource->plural, Stubs::starter($project, $folder, $resource->plural), $split);
            $this->registered[] = [$resource->plural, $folder];

            return;
        }

        $variable = Names::snake($resource->name);
        ComponentFiles::add($plan, $project, $folder, $resource->plural, Stubs::list($project, $folder, $resource->plural, $model, $variable, $resource->table, $resource->fields), $split);
        $this->registered[] = [$resource->plural, $folder];

        $formFolder = ['Forms', ...$resource->namespace];
        $dataClass = $resource->name . 'Data';
        $plan->add($project->sourcePath([...$formFolder, $dataClass]), Stubs::formData($project, $formFolder, $dataClass, $resource->fields));

        $formClass = $resource->name . 'Form';
        $ability = $resource->table . '.write';
        ComponentFiles::add($plan, $project, $folder, $formClass, Stubs::form($project, $folder, $formClass, $model, $dataClass, $project->namespaceFor($formFolder) . '\\' . $dataClass, $ability, $resource->fields), $split);
        $this->registered[] = [$formClass, $folder];
    }

    public function finish(Resource $resource, Project $project, Arguments $arguments): array
    {
        $lines = [];

        if ($arguments->has('split')) {
            foreach ($this->registered as [$class, $folder]) {
                $lines[] = ComponentFiles::register($project, $folder, $class);
            }
        }

        $lines[] = sprintf('Show it where you want: <%s />', $resource->plural);

        foreach ($this->registered as [$class]) {
            if (str_ends_with($class, 'Form')) {
                $lines[] = sprintf("%s is locked: define the ability '%s' (a Shield policy) before using it. Edit with <%s :id=\"5\" />.", $class, $resource->table . '.write', $class);
            }
        }

        return $lines;
    }
}
