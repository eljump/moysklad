<?php

namespace MoySklad\Components\Specs;


class LinkingSpecs extends AbstractSpecs {
    protected static $cachedDefaultSpecs = null;
    public function getDefaults()
    {
        return [
            'name' => null,
            'fields' => null,
            "multiple" => false
        ];
    }
}