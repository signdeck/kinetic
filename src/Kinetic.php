<?php

namespace Ambengers\Kinetic;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use Inertia\ProvidesInertiaProperties;
use Inertia\Response;
use Inertia\ResponseFactory;
use InvalidArgumentException;
use UnitEnum;

/**
 * @mixin \Inertia\ResponseFactory
 */
class Kinetic extends ResponseFactory
{
    /** @var array */
    protected $composedProps = [];

    public function flushShared(): void
    {
        $this->composedProps = [];
        parent::flushShared();
    }

    /**
     * `$component` stays untyped on purpose: Inertia's ResponseFactory::render() takes
     * the argument untyped in some releases (3.8.0), and narrowing it here makes PHP
     * reject the override with a fatal error.
     *
     * @param  BackedEnum|UnitEnum|string  $component
     * @param  array|Arrayable|ProvidesInertiaProperties  $props
     * @return Response
     */
    public function render($component, $props = []): Response
    {
        try {
            $component = $this->componentName($component);

            if (! is_string($component)) {
                throw new InvalidArgumentException('Component argument must be of type string or a string BackedEnum');
            }

            $this->resolveComposedProps($component);

            if ($props instanceof Arrayable) {
                $props = $props->toArray();
            } elseif ($props instanceof ProvidesInertiaProperties) {
                // Resolved later by Inertia\PropsResolver: keep the provider first so that
                // composed props win over its keys, exactly like over regular page props.
                $props = [$props];
            }

            // componentName() already applied the transformer. Suspend it so
            // parent::render() does not apply it a second time.
            $transformer = $this->componentTransformer;
            $this->componentTransformer = null;

            try {
                return parent::render($component, array_merge($props, $this->composedProps));
            } finally {
                $this->componentTransformer = $transformer;
            }
        } finally {
            // parent::render() copied the props into a new Response, so the accumulated
            // ones are only needed for one render. Without this, a second render in the
            // same request (e.g. an error page after a rendered page) inherits them.
            $this->composedProps = [];
        }
    }

    /**
     * Mirror ResponseFactory::render(): transform the component, then resolve an
     * enum to the name the client sees.
     */
    protected function componentName(mixed $component): mixed
    {
        $component = $this->transformComponent($component);

        return match (true) {
            $component instanceof BackedEnum => $component->value,
            $component instanceof UnitEnum => $component->name,
            default => $component,
        };
    }

    /**
     * @param  string|array             $components
     * @param  \Closure|array|string    $composers
     *
     * @return self
     */
    public function composer($components, $composers): self
    {
        $this->composerBag()->set($components, $composers);

        return $this;
    }

    /**
     * @param  string|array  $key
     * @param  mixed|null  $value
     *
     * @return self
     */
    public function with($key, $value = null): self
    {
        if (is_array($key)) {
            $this->composedProps = array_merge($this->composedProps, $key);
        } else {
            $this->composedProps[$key] = $value;
        }

        return $this;
    }

    /**
     * @return \Ambengers\Kinetic\ComposerBag
     */
    protected function composerBag()
    {
        return app(ComposerBag::class);
    }

    /**
     * @param  string $component
     * @return void
     */
    protected function resolveComposedProps(string $component) : void
    {
        $this->composerBag()->compose($component);
    }
}
