<?php

namespace Atom\DI\Tests\Misc\CircularDependency;

class CDDummy2
{
    public function __construct(CDDummy1 $dm)
    {
    }
}
