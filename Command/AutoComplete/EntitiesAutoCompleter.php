<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sensio\Bundle\GeneratorBundle\Command\AutoComplete;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Provides auto-completion suggestions for entities.
 *
 * @author Charles Sarrazin <charles@sarraz.in>
 */
class EntitiesAutoCompleter
{
    const APPLY_REPLACEMENTS = true;

    const NO_REPLACEMENTS = false;

    private $manager;

    public function __construct(EntityManagerInterface $manager)
    {
        $this->manager = $manager;
    }

    /**
     * @param bool $applyNamespaceReplacements
     * 
     * @return array
     */
    public function getSuggestions($applyNamespaceReplacements = self::APPLY_REPLACEMENTS)
    {
        $configuration = $this->manager
            ->getConfiguration()
        ;

        $entities = $configuration
            ->getMetadataDriverImpl()
            ->getAllClassNames()
        ;

        if ($applyNamespaceReplacements == true) {
            $namespaceReplacements = array();

            foreach ($configuration->getEntityNamespaces() as $alias => $namespace) {
                $namespaceReplacements[$namespace.'\\'] = $alias.':';
            }

            $entities = array_map(function ($entity) use ($namespaceReplacements) {
                return strtr($entity, $namespaceReplacements);
            }, $entities);
        }

        return $entities;
    }
}
