<?php
/**
 * LoadOrganizer.php
 * lasso
 * Date: 17.05.15
 */

class Script {

    protected $src;
    protected $gives;
    protected $needs;
    protected $complex;
    protected $compound;

    function __construct($src, $gives, $needs = array())
    {
        $this->src   = $src;
        $this->gives = $gives;
        $this->needs = $needs;
        $this->complex = (count($needs) > 0);
        $this->compound = (count($gives) > 1);
    }

    public function __toString()
    {
        return $this->src;
    }

    public function supports($id)
    {
        return in_array($id, $this->gives);
    }

    /**
     * @return mixed
     */
    public function getSrc()
    {
        return $this->src;
    }

    /**
     * @return mixed
     */
    public function getGives()
    {
        return $this->gives;
    }

    /**
     * @return mixed
     */
    public function getNeeds()
    {
        return $this->needs;
    }

    /**
     * @return boolean
     */
    public function isComplex()
    {
        return $this->complex;
    }

    /**
     * @return boolean
     */
    public function isCompound()
    {
        return $this->compound;
    }

    public function addsComplexity($context)
    {
        $diff = array_diff($this->needs, $context);
        return (sizeof($diff) > 0);
    }

    public function getMatches($context)
    {
        $matches = array_intersect($this->gives, $context);
        return $matches;
    }

    public function matchesMore($matches, $context)
    {
        $mine   = $this->getMatches($context);
        return (sizeof($mine) > sizeof($matches));
    }

    public function requires($id)
    {
        return in_array($id, $this->needs);
    }

    /**
     * @param Script[] $previous
     * @param Script $new
     *
     * @return bool
     */
    public function accepts($previous, $new)
    {
        foreach($this->needs as $need) {

            // as long as one of our predecessors supports our need we're good.
            foreach($previous as $script) {
                if($script->supports($need)) continue 2;
            }

            // if no one else does, the new guy should
            if(! $new->supports($need) ) {
                return false;
            }
        }

        // none of our predecessors nor the new guy matches our requirements.
        return true;
    }
}


class LoadOrganizer
{


    // register

    /**
     * @var Script[]
     */
    protected $sources    = [];
    /**
     * @var array
     */
    protected $queued    = [];

    protected $loadOrder = [];

    public function register($src, $gives = array(), $needs = array())
    {
        $this->sources[$src] = new Script($src, $gives, $needs); // [$src, $gives, $needs, count($gives), count($needs)];
    }

    // queue

    protected function isSupported($id)
    {
        foreach($this->sources as $source) {
            if($source->supports($id)) return true;
        }

        return false;
    }

    public function queue($id)
    {
        if(!$this->isSupported($id)) {
            throw new InvalidArgumentException(sprintf(
                "Invalid Argument [%s]. There is no registered script providing the given id.",
                $id
            ));
        }

        if(! in_array($id ,$this->queued)) {
            $this->queued[] = $id;
        }


        return $this;
    }

    // resolve

    public function resolve()
    {
        $start = microtime(true);

        $loadOrder = $this->buildLoadOrder($this->queued);
        $sources   = $this->getSimpleSources($loadOrder);
        $optimized = $this->optimize($sources);

        $end = (microtime(true) - $start);

        echo sprintf("executed in %0.5f milliseconds" . PHP_EOL, ($end * 1000));

        return array_unique($optimized);
    }

    protected function optimize($sources)
    {
        foreach($sources as $id => $source) {
            $script = $this->findSupportingCompound($id, $sources);
            if(! $script ) continue;

            foreach($script->getGives() as $replaceAt) {
                $sources[$replaceAt] = $script;
            }

        }

        return $sources;
    }

    protected function getSimpleSources($loadOrder)
    {
        $scripts = [];
        foreach($loadOrder as $id) {
            $script = $this->findSupporting($id);
            if(! $script ) continue;
            $scripts[$id] = $script;
        }

        return $scripts;
    }

    protected function getLoadSequence($id, $map = [])
    {
        $source = $this->findSupporting($id, []);
        if( $source->isComplex()) {
            foreach($source->getNeeds() as $needsId) {

                if(in_array($needsId, $map)) {
                    continue;
                }

                foreach($this->getLoadSequence($needsId, $map) as $subId) {
                    in_array($subId, $map) or $map[] = $subId;
                }
            }
        }
        in_array($id, $map) or $map[] = $id;
        return $map;
    }

    public function buildLoadOrder($queue = [], $subQueue = [], $loop = 0)
    {
        $sequence = [];
        foreach($queue as $queuedId) {
            $sequence = $this->getLoadSequence($queuedId, $sequence);
        }
        return $sequence;
    }

    protected function findSupporting($id, $idList = [])
    {
        foreach($this->sources as $source) {
            if( $source->isCompound() ) continue;
            if( $source->supports($id) ) return $source;
        }

        return $this->findSupportingCompound($id, $idList, false);
    }

    protected function findSupportingCompound($id, $sourceList, $denyComplexityRaise = true)
    {
        $ids       = array_keys($sourceList);
        $lastMatch = false;
        foreach($this->sources as $src => $source) {
            // only compounds (gives > 1)
            if(! $source->isCompound() ) continue;
            // only compounds supporting our id
            if(! $source->supports( $id )) continue;
            // check for more complexity (e.g. extra dependencies)
            if( $source->addsComplexity( $ids ) && $denyComplexityRaise ) continue;

            $n = 0;
            foreach($sourceList as $id => $permittee) {

                $slice = array_slice($sourceList, 0, ++$n);

                // ask every concerned script if the replacement is acceptable
                // taking all predecessors into account.
                if( ! $permittee->accepts($slice, $source) ) {
                    // if anyone rejects, we skip to the next candidate.
                    continue 2;
                }
            }

            // choose the one which provides more resolved dependencies
            $lastMatch = $this->getPreferred($lastMatch, $source, $ids);
        }

        return $lastMatch;
    }

    /**
     * @param Script $left
     * @param Script $right
     * @param $context
     *
     * @return mixed
     */
    protected function getPreferred($left, $right, $context)
    {
        if( ! $left ) return $right;
        if( ! $right ) return $left;

        $leftMatches  = $left->getMatches($context);
        $rightMatches = $right->getMatches($context);
        return
            (count($rightMatches) > count($leftMatches))
            ? $right: $left;
    }

    protected function isCompound($src)
    {
        if(!isset($this->sources[$src])) return false;
        $source = $this->sources[$src];
        return $source->isCompound();
    }

    // optimize


}