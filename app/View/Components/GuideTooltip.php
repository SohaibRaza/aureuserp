<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class GuideTooltip extends Component
{
    public function __construct(
        public string $title,
        public string $content,
        public ?string $learnMore = null,
    ) {}

    public function render(): View
    {
        return view('components.guide-tooltip');
    }
}
