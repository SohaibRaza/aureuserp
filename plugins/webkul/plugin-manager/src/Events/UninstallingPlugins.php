<?php

namespace Webkul\PluginManager\Events;

use Illuminate\Foundation\Events\Dispatchable;

class UninstallingPlugins
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $names
     */
    public function __construct(public array $names) {}
}
