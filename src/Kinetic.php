<?php

namespace Ambengers\Kinetic;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use Inertia\ProvidesInertiaProperties;
use Inertia\Response;
use Inertia\ResponseFactory;
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
            $this->resolveComposedProps($this->componentName($component));

            if ($props instanceof Arrayable) {
                $props = $props->toArray();
            } elseif ($props instanceof ProvidesInertiaProperties) {
                // Resolved later by Inertia\PropsResolver: keep the provider first so that
                // composed props win over its keys, exactly like over regular page props.
                $props = [$props];
            }

            return parent::render($component, array_merge($props, $this->composedProps));
        } finally {
            // parent::render() copied the props into a new Response, so the accumulated
            // ones are only needed for one render. Without this, a second render in the
            // same request (e.g. an error page after a rendered page) inherits them.
            $this->composedProps = [];
        }
    }

    /**
     * The component name the client will see: `transformComponent()` plus enum
     * normalization, both applied by ResponseFactory::render() afterwards.
     */
    protected function componentName($component): string
    {
        $component = $this->transformComponent($component) ?? $component;

        return match (true) {
            $component instanceof BackedEnum => (string) $component->value,
            $component instanceof UnitEnum => $component->name,
            is_string($component) => $component,
            default => '',
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
