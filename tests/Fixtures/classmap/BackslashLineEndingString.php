<?php

declare(strict_types=1);

namespace Foo;

class SlashedA
{
    public function foo()
    {
        return sprintf("foo\
                        bar");
    }
}

class SlashedB
{
    public function bar()
    {
        print 'baz';
    }
}
