<?php

class Organizer {

    const POSITION_HEAD   = 1;
    const POSITION_BOTTOM = 2;

    /**
     * holds registered script sources
     * @var array
     */
    protected $files       = [];

    /**
     * Holds the resolved load order
     * @var array
     */
    protected $order        = [];

    protected $orderGroup   = [];

    /**
     * holds queued ids in groups
     * @var array
     */
    protected $queueGroups = [];

    /**
     * holds queued ids in one stack
     * @var array
     */
    protected $queued      = [];

    /**
     * holds resolved states for ids
     * @var array
     */
    protected $resolved    = [];

    protected $checked     = [];

    protected $loops       = 0;

    protected $resolvable = [];

    protected $matches    = [];

    public function __construct($config = array())
    {
        $config = (object)$config;
        if(isset($config->scripts)) {
            $this->configure($config->scripts);
        }
    }


    public function configure($config = array())
    {
        foreach($config as $src => $args) {
            $args = (object) $args;
            $this->register($src, $args->provides, $args->requires);
        }
    }

    public function register( $src, $provides = array(), $requires = array() )
    {
        $this->files[$src] = (object)[
                "provides" => $provides,
                "requires" => $requires,
            ];
    }


    public function queue($id, $position = Organizer::POSITION_BOTTOM)
    {
        // create group if not present...
        isset($this->queueGroups[$position]) or $this->queueGroups[$position] = [];

        if(! in_array($id, $this->queued)) {
            // store as queued
            $this->queued[] = $id;

            // ... and store id to group
            $this->queueGroups[$position][] = $id;
            // done
            return $this;
        }

        // id is present, do nothing
        if(in_array($id, $this->queueGroups[$position]))
            return $this;


        if($position == Organizer::POSITION_BOTTOM) {
            if(in_array($id, $this->queueGroups[Organizer::POSITION_HEAD])) {
                return $this;
            }

            $this->queueGroups[$position][] = $id;
            return $this;
        }

        if($position == Organizer::POSITION_HEAD) {
            $this->queueGroups[$position][] = $id;
            if(false !== ($key = array_search($id, $this->queueGroups[Organizer::POSITION_BOTTOM]))) {
                unset($this->queueGroups[$key]);
            }
        }

        return $this;
    }

    public function getLoadOrder($group) {

        $now = microtime(true);

        if(! array_key_exists($group, $this->queueGroups)) {
            return [];
        }

        if( array_key_exists($group, $this->orderGroup)) {
            return $this->orderGroup[$group];
        }

        $this->failOnCircularReference();

        $this->resolve($this->queueGroups[$group]);

        $this->orderGroup[$group] = $this->order;
        $this->order              = [];

        printf( "execution time : %0.5f milliseconds".PHP_EOL,   (microtime(true) - $now) * 1000);
        return $this->orderGroup[$group];
    }

    protected function addComplex($match)
    {
        $seek = array_diff( $match->requires, $match->complex);
        if(count($seek) == 0) {
            array_splice($this->order, 0, 0, $match->complex);
            return;
        }

        $pos = count($seek);
        foreach($seek as $requires) {
            $key = ((int)array_search($requires, $this->order) +1);
            if($key > $pos) $pos = $key;
        }
        array_splice($this->order, ($pos), 0, $match->complex);
    }

    public function resolve($ids = [], $n = 0, $lastMatch = false)
    {

        echo "loop $n".PHP_EOL;
        $ids = array_unique($ids);

        // check for general resolvability
        if(true !== ($result = $this->isResolvable($ids))) {
            throw new RuntimeException(sprintf(
                "Unable to resolve requested scripts. Missing ids: %s",
                implode(', ', $result)
            ));
        }

        foreach($ids as $id) {
            in_array($id, $this->order) or $this->order[] = $id;
        }

        // find best matching file to resolve as many dependencies as possible in one go
        $bestMatch = $this->findBestMatch($ids, $this->order, $lastMatch);

        // add complexity to queue
        if($bestMatch->complexityCount > 0) {
            $this->addComplex($bestMatch);
            $ids = $this->order;
        }

        $resolved    = $this->resolved;
        $resolves    = $bestMatch->provides;

        // find all unresolved ids

        /*
        $unresolved = array_filter($bestMatch->requires, function($id) use ($resolved, $resolves){
            return (! array_key_exists($id, $resolved)) && (! in_array($id ,$resolves));
        });
        */

        // register id as resolved and append to load order
        foreach ($resolves as $id) {
            $this->resolved[$id] = $bestMatch->src;
        }

        $resolved  = $this->resolved;
        // find all still unresolved dependencies
        $remaining = array_filter($ids, function($id) use ($resolved, $resolves) {
            return  (! array_key_exists($id, $resolved) );
        } );

        // exit recursion as soon as everything is resolved
        if(count($remaining) == 0) {
            $sequence = [];
            foreach($this->order as $id) {
                $sequence[$id] = $this->resolved[$id];
            }

            $this->order = array_unique(array_values($sequence));

            return;
        }

        $this->resolve($remaining, ++$n, $bestMatch);

    }


    protected function isResolvable($requiring)
    {

            foreach ($this->files as $src => $config) {
                $requiring = $this->reduceMatches($requiring, $config->provides);
                if (count($requiring) == 0) {
                    return true;
                }
            }
            return count($requiring) > 0 ? $requiring : true;
    }

    /**
     * Returns a list of entries from $requiring which are not present in $providing
     * @param $requiring
     * @param $providing
     *
     * @return array
     */
    protected function reduceMatches($requiring, $providing)
    {
        $key = implode('-', $requiring) . implode('-', $providing);

        if(! isset($this->resolvable[$key])) {
            $this->resolvable[$key] = array_filter( $requiring,
                function ($item) use ($providing) {
                    return (!in_array($item, $providing));
                }
            );
        }
        return $this->resolvable[$key];
    }

    protected function buildMatchList($ids)
    {
        $key = implode('-', $ids);

        if(! isset($this->matches[$key])) {

            $candidates = [];

            foreach ($this->files as $src => $config) {
                $provides = $config->provides;
                $requires = $config->requires;

                // look for resolvable ids
                $found = array_filter($ids, function ($id) use ($provides) {
                        return in_array($id, $provides);
                    });

                // no contender, disqualify
                if (count($found) == 0) {
                    continue;
                }

                // no further dependencies ( complexity = 0 )
                if (count($requires) == 0) {
                    $candidates[] = (object)[
                        'matchCount'      => count($found),
                        'complexityCount' => 0,
                        'src'             => $src,
                        'provides'        => $provides,
                        'requires'        => $requires,
                        'complex'         => []
                    ];
                    continue;
                }

                // disqualify unresolvable dependencies
                if (true !== $this->isResolvable($requires)) {
                    continue;
                }

                // complexity: required ids which are not yet queued
                $complexity = array_filter($requires, function ($id) use ($ids) {
                        return (!in_array($id, $ids));
                    });

                $candidates[] = (object)[
                    'matchCount'      => count($found),
                    'complexityCount' => count($complexity),
                    'src'             => $src,
                    'provides'        => $provides,
                    'requires'        => $requires,
                    'complex'         => $complexity
                ];
            }

            // sort candidates by amount of provided ids in comparison to added complexity
            usort($candidates, function($left, $right) {

                $leftWeight  = $left->matchCount  - $left->complexityCount;
                $rightWeight = $right->matchCount - $right->complexityCount;

                if($leftWeight == $rightWeight) return 0;

                return $rightWeight - $leftWeight; //($left > $right) ? -1 : 1;
            });

            foreach($candidates as $n => $candidate) {
                echo "[$n] $key : {$candidate->src} ({$candidate->matchCount} / {$candidate->complexityCount})".PHP_EOL;
            }

            $this->matches[$key] = $candidates;
        }

        return $this->matches[$key];
    }


    public function findBestMatch($ids, $fullSet) {

        // find candidates providing as many ids as possible

        $candidates = $this->buildMatchList($fullSet);

        $baseDiff = implode('', $fullSet) . implode('', $ids );

        foreach($candidates as $n => $candidate) {

            $diff = $baseDiff . implode('', $candidate->provides);

            if(false !== ($conflictId = $this->isConflicting($candidate))) {
                $this->checked[$candidate->src][] = $diff;
                continue;
            }

            foreach($this->checked as $checked => $setList) {
                foreach($setList as $set) {
                    if($set !== $diff) continue;
                }

                if($candidate->provides == $ids) {
                    return $candidate;
                }

                if ($checked == $candidate->src) {
                    echo "skipping $checked. Already used for these ids... ". $diff.PHP_EOL; // implode(', ', $diff).PHP_EOL;
                    continue 2;
                }

            }
            echo "using {$candidate->src}".PHP_EOL;
            $this->checked[$candidate->src][] = $diff;
            return $candidate;
        }

        // no match found

        return $this->getSingleIdMatch($ids, $candidates) ? : $candidate;
    }

    protected function getSingleIdMatch($ids, $candidates)
    {
        reset($ids);
        $singleId = [current($ids)];
        echo "using single id match " . implode(', ', $singleId) . PHP_EOL;
        foreach($candidates as $possibleMatch) {
            if(sizeof($possibleMatch->provides) > 1) continue;
            if($possibleMatch->provides == $singleId)
                return $possibleMatch;
        }

        return false;
    }

    protected function isConflicting($candidate) {

        return false;
        foreach($this->resolved as $id => $resolvedSrc) {
            if( ! in_array($id, $candidate->provides) ) continue;
            if($resolvedSrc == $candidate->src) continue;
            if($candidate->matchCount > 1) continue;
            echo "$id in {$candidate->src} conflicting with resolved $resolvedSrc" . PHP_EOL;

            return $id;
        }

        return false;
    }

    protected function failOnCircularReference()
    {
        foreach($this->files as $src => $config) {
            $gives = $config->provides;
            $needs = $config->requires;

            if(count($needs) == 0) {
                continue;
            }

            $circular = array_filter($this->files, function($leaf) use ( $gives, $needs) {

                $leafGives = $leaf->provides;
                $leafNeeds = $leaf->requires;

                // skip empty requirements
                if(count($leafNeeds) == 0) return false;


                $leafDiff = array_diff($leafNeeds, $gives);
                $rootDiff = array_diff($needs, $leafGives);

                return (sizeof($leafDiff) == 0 && sizeof($rootDiff) == 0);

            });

            if(count($circular) > 0) {
                $reference = current($circular);
                $msg = sprintf(
                    "Circular reference detected: file %s requires %s requiring %s",
                    $src, implode(', ', $needs), implode(', ', $reference->requires));

                throw new RuntimeException($msg);
            }

        }
    }
}
