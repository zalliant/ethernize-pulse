<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Cli;

use Ethernize\Core\Cli\Arguments;
use Ethernize\Core\Cli\Generator\GeneratorCommand;
use Ethernize\Core\Cli\Generator\Plan;
use Ethernize\Core\Cli\Generator\Project;
use Ethernize\Pulse\SingleFile\ComponentSource;
use Ethernize\Core\Cli\UsageError;

/**
 * `php ethernize make:pulse Products`: a Pulse component. By default the class and the template go in one
 * file, src/Pulse/Products.pulse.php. With --split they go in two: the class src/Pulse/Products.php and the
 * template resources/views/pulse/products.weave, registered in config/pulse.php.
 */
final class MakePulseCommand extends GeneratorCommand
{
    private bool $split = false;
    private ?string $registered = null;

    public function name(): string
    {
        return 'make:pulse';
    }

    public function summary(): string
    {
        return 'Create a Pulse component (one file, or --split into class and template)';
    }

    public function usage(): string
    {
        return implode("\n", [
            'Usage: php ethernize make:pulse <Name> [--split] [options]',
            '',
            'Creates a reactive component with a counter to start from.',
            '',
            '  (default)   one file, src/Pulse/<Name>.pulse.php: the class, then ?> and the Weave template.',
            '              It is found by its file name; nothing to register.',
            '  --split     two files: src/Pulse/<Name>.php and resources/views/pulse/<name>.weave, and the',
            '              component is added to config/pulse.php.',
            $this->commonUsage(),
            '',
            'The name is PascalCase: Products becomes the tag <Products /> and the component "products";',
            'ProductForm becomes <ProductForm /> and "product-form".',
            '',
            'Examples:',
            '  php ethernize make:pulse Products',
            '  php ethernize make:pulse Admin/ProductForm --split',
        ]);
    }

    protected function options(): array
    {
        return ['split'];
    }

    protected function plan(Arguments $arguments, Project $project, Plan $plan): void
    {
        ['namespace' => $sub, 'class' => $class] = $this->className($arguments, 'Products');

        try {
            ComponentSource::nameFor($class);
        } catch (\InvalidArgumentException $exception) {
            throw new UsageError($exception->getMessage());
        }

        $folder = ['Pulse', ...$sub];
        $this->split = $arguments->has('split');
        $this->registered = $this->split ? $class . '|' . implode('/', $folder) : null;
        ComponentFiles::add($plan, $project, $folder, $class, Stubs::starter($project, $folder, $class), $this->split);
        $plan->note(sprintf('Use it in a template: <%s />', $class));
    }

    protected function afterWrite(Arguments $arguments, Project $project): array
    {
        if (!$this->split || $this->registered === null) {
            return [];
        }

        [$class, $folder] = explode('|', $this->registered, 2);

        return [ComponentFiles::register($project, explode('/', $folder), $class)];
    }
}
