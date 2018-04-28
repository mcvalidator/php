<?php

use McValidator as MV;
use PHPUnit\Framework\TestCase;

class Test extends TestCase
{
    public static function setUpBeforeClass()
    {
        \McValidator\Base::createFilter('merge', function (\McValidator\Data\Capsule $capsule, \McValidator\Data\State $state) {
            $new = $capsule->getOptions()->getValue();

            return $capsule->newValue(function ($value) use ($new) {
                if ($value === null) {
                    return $new;
                } else if ($value instanceof \Heterogeny\Dict) {
                    return $value->merge($new);
                }

                throw new Exception('Nope');
            });
        });

        \McValidator\Base::createFilter('replace', function (\McValidator\Data\Capsule $capsule, \McValidator\Data\State $state) {
            return $capsule->newValue($capsule->getOptions()->getValue());
        });

        \McValidator\Base::createFilter('truncate', function (\McValidator\Data\Capsule $capsule, \McValidator\Data\State $state) {
            $limit = $capsule->getOptions()->getOrElse('limit', 10);
            $end = $capsule->getOptions()->getOrElse('end', '...');

            return $capsule->newValue(function ($str) use ($limit, $end) {
                return MV\Support\Str::limit($str, $limit, $end);
            });
        });
    }

    /**
     * @throws Exception
     */
    public function test1()
    {
        $builder = MV\valid(
            MV\section('filter@replace', 20),
            MV\section('filter@replace', 30),
            MV\section('filter@replace', 40)
        );

        $validator = $builder->build();

        $x = $validator->pump(10);

        $v1 = $x->getOldValue();
        $v2 = $v1->getOldValue();
        $v3 = $v2->getOldValue();
        $v4 = $v3->getOldValue();

        $this->assertTrue($x->get() === 40);
        $this->assertTrue($v1->get() === 30);
        $this->assertTrue($v2->get() === 20);
        $this->assertTrue($v3->get() === 10);
        $this->assertTrue($v4 === null);
    }

    /**
     * @throws Exception
     */
    public function test2()
    {
        $builder = MV\shape_of([
            'a' => MV\valid(
                MV\section('filter@replace', 20),
                MV\section('filter@replace', 30),
                MV\section('filter@replace', 40)
            ),
            // when merging you need to defined the values which will be merge,
            // mcvalidator wont let foreign data to stay on its values.
            'b' => MV\section('rule@is-string')
        ], MV\section('filter@merge', dict(['b' => 'c'])));

        $validator = $builder->build();

        $x = $validator->pump(dict([
            'a' => 10
        ]));

        $a = $x->get()->get('a');
        $b = $x->get()->get('b');

        $this->assertEquals(40, $a);
        $this->assertEquals('c', $b);
    }

    /**
     * @throws Exception
     */
    public function testNested()
    {
        $builder = MV\shape_of([
            'a' => MV\shape_of([
                'b' => MV\shape_of([
                    'c' => MV\list_of(
                        MV\section('filter@replace', 20),
                        MV\section('filter@replace', 30),
                        MV\section('filter@replace', 40)
                    )
                ])
            ])
        ]);

        $validator = $builder->build();

        $x = $validator->pump(dict([
            'a' => dict([
                'b' => dict([
                    'c' => seq(1, 2, 3, 4)
                ])
            ])
        ]));

        $y = $validator->pump(dict([]));

        $a = $x->get();
        $b = $y->get();
        $c = $y->get(true, true, true);

        $d = $a->getOrElse('a/b/c');
        $e = $b->getOrElse('a/b/c');
        $f = $c->getOrElse('a/b/c');

        $this->assertTrue(
            $d->equals(seq(40, 40, 40, 40))
        );

        $this->assertTrue(
            $e === null
        );

        $this->assertTrue(
            $f instanceof \Heterogeny\Seq
        );
    }

    public function testYaml()
    {
        $yml = "- rule@is-string";
        $validator = McValidator\Parser\Yaml::parseSingle($yml);

        $this->assertInstanceOf(MV\Support\ValidBuilder::class, $validator);

        $validator = $validator->build();

        $result = $validator->pump(10);

        $this->assertInstanceOf(MV\Data\InvalidValue::class, $result);
    }

    public function testYaml2()
    {
        $yml = <<<YAML
!shape-of
_:
  filter@merge:
    c: "20"
a:
  - rule@is-string 
  - filter@truncate:
      limit: 10
      end: …
b:
  - filter@to-string:
  - rule@is-string:

c:
  - rule@is-string
YAML;

        $validators = McValidator\Parser\Yaml::parseSingle($yml);

        $this->assertInstanceOf(MV\Support\ShapeOfBuilder::class, $validators);

        $validator = $validators->build();

        $this->assertInstanceOf(MV\Contracts\Pipeable::class, $validator);

        $result = $validator->pump(dict([
            'a' => 'verylongword',
            'b' => dict([
                'c' => dict([
                    'd' => dict([
                        'e' => 10
                    ])
                ])
            ])
        ]));

        $hasBError = !$result->getState()->getErrors()->filter(function (McValidator\Data\Error $x) {
            return $x->getField()->getPath() === ['$', 'b'];
        })->isEmpty();

        $this->assertTrue($hasBError);

        $value = $result->get()->all();

        $this->assertEquals(['a' => 'verylongwo…', 'c' => '20'], $value);
    }
}