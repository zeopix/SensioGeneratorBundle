<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sensio\Bundle\GeneratorBundle\Command;

use Sensio\Bundle\GeneratorBundle\Generator\DoctrineEntityGenerator;
use Sensio\Bundle\GeneratorBundle\Command\Helper\QuestionHelper;
use Sensio\Bundle\GeneratorBundle\Command\AutoComplete\EntitiesAutoCompleter;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Doctrine\DBAL\Types\Type;

/**
 * Initializes a Doctrine entity inside a bundle.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class GenerateDoctrineEntityCommand extends GenerateDoctrineCommand
{
    protected function configure()
    {
        $this
            ->setName('doctrine:generate:entity')
            ->setAliases(array('generate:doctrine:entity'))
            ->setDescription('Generates a new Doctrine entity inside a bundle')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'The entity class name to initialize (shortcut notation)')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'The fields to create with the new entity')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Use the format for configuration files (php, xml, yml, or annotation)', 'annotation')
            ->setHelp(<<<EOT
The <info>doctrine:generate:entity</info> task generates a new Doctrine
entity inside a bundle:

<info>php app/console doctrine:generate:entity --entity=AcmeBlogBundle:Blog/Post</info>

The above command would initialize a new entity in the following entity
namespace <info>Acme\BlogBundle\Entity\Blog\Post</info>.

You can also optionally specify the fields you want to generate in the new
entity:

<info>php app/console doctrine:generate:entity --entity=AcmeBlogBundle:Blog/Post --fields="title:string(255) body:text"</info>

By default, the command uses annotations for the mapping information; change it
with <comment>--format</comment>:

<info>php app/console doctrine:generate:entity --entity=AcmeBlogBundle:Blog/Post --format=yml</info>

To deactivate the interaction mode, simply use the `--no-interaction` option
without forgetting to pass all needed options:

<info>php app/console doctrine:generate:entity --entity=AcmeBlogBundle:Blog/Post --format=annotation --fields="title:string(255) body:text" --with-repository --no-interaction</info>
EOT
        );
    }

    /**
     * @throws \InvalidArgumentException When the bundle doesn't end with Bundle (Example: "Bundle/MySampleBundle")
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $questionHelper = $this->getQuestionHelper();

        $entity = Validators::validateEntityName($input->getOption('entity'));
        list($bundle, $entity) = $this->parseShortcutNotation($entity);
        $format = Validators::validateFormat($input->getOption('format'));
        $fields = $this->parseFields($input->getOption('fields'));

        $questionHelper->writeSection($output, 'Entity generation');

        $bundle = $this->getContainer()->get('kernel')->getBundle($bundle);

        /** @var DoctrineEntityGenerator $generator */
        $generator = $this->getGenerator();
        $generatorResult = $generator->generate($bundle, $entity, $format, array_values($fields));

        $output->writeln(sprintf(
            '> Generating entity class <info>%s</info>: <comment>OK!</comment>',
            $this->makePathRelative($generatorResult->getEntityPath())
        ));
        $output->writeln(sprintf(
            '> Generating repository class <info>%s</info>: <comment>OK!</comment>',
            $this->makePathRelative($generatorResult->getRepositoryPath())
        ));
        if ($generatorResult->getMappingPath()) {
            $output->writeln(sprintf(
                '> Generating mapping file <info>%s</info>: <comment>OK!</comment>',
                $this->makePathRelative($generatorResult->getMappingPath())
            ));
        }

        $questionHelper->writeGeneratorSummary($output, array());
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $questionHelper = $this->getQuestionHelper();
        $questionHelper->writeSection($output, 'Welcome to the Doctrine2 entity generator');

        // namespace
        $output->writeln(array(
            '',
            'This command helps you generate Doctrine2 entities.',
            '',
            'First, you need to give the entity name you want to generate.',
            'You must use the shortcut notation like <comment>AcmeBlogBundle:Post</comment>.',
            '',
        ));

        $bundleNames = array_keys($this->getContainer()->get('kernel')->getBundles());

        while (true) {
            $question = new Question($questionHelper->getQuestion('The Entity shortcut name', $input->getOption('entity')), $input->getOption('entity'));
            $question->setValidator(array('Sensio\Bundle\GeneratorBundle\Command\Validators', 'validateEntityName'));
            $question->setAutocompleterValues($bundleNames);
            $entity = $questionHelper->ask($input, $output, $question);

            list($bundle, $entity) = $this->parseShortcutNotation($entity);

            // check reserved words
            if ($this->getGenerator()->isReservedKeyword($entity)) {
                $output->writeln(sprintf('<bg=red> "%s" is a reserved word</>.', $entity));
                continue;
            }

            try {
                $b = $this->getContainer()->get('kernel')->getBundle($bundle);

                if (!file_exists($b->getPath().'/Entity/'.str_replace('\\', '/', $entity).'.php')) {
                    break;
                }

                $output->writeln(sprintf('<bg=red>Entity "%s:%s" already exists</>.', $bundle, $entity));
            } catch (\Exception $e) {
                $output->writeln(sprintf('<bg=red>Bundle "%s" does not exist.</>', $bundle));
            }
        }
        $input->setOption('entity', $bundle.':'.$entity);

        // format
        $output->writeln(array(
            '',
            'Determine the format to use for the mapping information.',
            '',
        ));

        $formats = array('yml', 'xml', 'php', 'annotation');

        $question = new Question($questionHelper->getQuestion('Configuration format (yml, xml, php, or annotation)', $input->getOption('format')), $input->getOption('format'));
        $question->setValidator(array('Sensio\Bundle\GeneratorBundle\Command\Validators', 'validateFormat'));
        $question->setAutocompleterValues($formats);
        $format = $questionHelper->ask($input, $output, $question);
        $input->setOption('format', $format);

        // fields
        $input->setOption('fields', $this->addFields($input, $output, $questionHelper));
    }

    private function parseFields($input)
    {
        if (is_array($input)) {
            return $input;
        }

        $fields = array();
        foreach (explode(' ', $input) as $value) {
            $elements = explode(':', $value);
            $name = $elements[0];
            if (strlen($name)) {
                $type = isset($elements[1]) ? $elements[1] : 'string';
                preg_match_all('/(.*)\((.*)\)/', $type, $matches);
                $type = isset($matches[1][0]) ? $matches[1][0] : $type;
                $length = isset($matches[2][0]) ? $matches[2][0] : null;

                $fields[$name] = array('fieldName' => $name, 'type' => $type, 'length' => $length);
            }
        }

        return $fields;
    }

    private function addFields(InputInterface $input, OutputInterface $output, QuestionHelper $questionHelper)
    {
        $fields = $this->parseFields($input->getOption('fields'));
        $output->writeln(array(
            '',
            'Instead of starting with a blank entity, you can add some fields now.',
            'Note that the primary key will be added automatically (named <comment>id</comment>).',
            '',
        ));
        $output->write('<info>Available types:</info> ');

        $types = array_keys(Type::getTypesMap());
        $count = 20;
        foreach ($types as $i => $type) {
            if ($count > 50) {
                $count = 0;
                $output->writeln('');
            }
            $count += strlen($type);
            $output->write(sprintf('<comment>%s</comment>', $type));
            if (count($types) != $i + 1) {
                $output->write(', ');
            } else {
                $output->write('.');
            }
        }
        $output->writeln('');

        $output->write('<info>Available relations:</info> ');

        $relations = array('many_to_many', 'many_to_one', 'one_to_one', 'one_to_many');
        foreach ($relations as $i => $relation) {
            $count += strlen($relation);
            $output->write(sprintf('<comment>%s</comment>', $relation));
            if (count($relations) != $i + 1) {
                $output->write(', ');
            } else {
                $output->write('.');
            }
        }
        $output->writeln('');

        $autocompleter = new EntitiesAutoCompleter($this->getContainer()->get('doctrine')->getManager());
        $autocompleteEntities = $autocompleter->getSuggestions(EntitiesAutoCompleter::NO_REPLACEMENTS);

        $mappings = array_merge($types, $relations);

        $fieldValidator = function ($type) use ($mappings) {
            // FIXME: take into account user-defined field types
            if (!in_array($type, $mappings)) {
                throw new \InvalidArgumentException(sprintf('Invalid type "%s".', $type));
            }

            return $type;
        };

        $lengthValidator = function ($length) {
            if (!$length) {
                return $length;
            }

            $result = filter_var($length, FILTER_VALIDATE_INT, array(
                'options' => array('min_range' => 1),
            ));

            if (false === $result) {
                throw new \InvalidArgumentException(sprintf('Invalid length "%s".', $length));
            }

            return $length;
        };

        while (true) {
            $output->writeln('');
            $generator = $this->getGenerator();
            $question = new Question($questionHelper->getQuestion('New field name (press <return> to stop adding fields)', null), null);
            $question->setValidator(function ($name) use ($fields, $generator) {
                if (isset($fields[$name]) || 'id' == $name) {
                    throw new \InvalidArgumentException(sprintf('Field "%s" is already defined.', $name));
                }

                // check reserved words
                if ($generator->isReservedKeyword($name)) {
                    throw new \InvalidArgumentException(sprintf('Name "%s" is a reserved word.', $name));
                }

                return $name;
            });

            $columnName = $questionHelper->ask($input, $output, $question);
            if (!$columnName) {
                break;
            }

            $defaultType = 'string';

            // try to guess the type by the column name prefix/suffix
            if (substr($columnName, -3) == '_at') {
                $defaultType = 'datetime';
            } elseif (substr($columnName, -3) == '_id') {
                $defaultType = 'integer';
            } elseif (substr($columnName, 0, 3) == 'is_') {
                $defaultType = 'boolean';
            } elseif (substr($columnName, 0, 4) == 'has_') {
                $defaultType = 'boolean';
            }

            $question = new Question($questionHelper->getQuestion('Field type', $defaultType), $defaultType);
            $question->setValidator($fieldValidator);
            $question->setAutocompleterValues($mappings);
            $type = $questionHelper->ask($input, $output, $question);

            $data = array('columnName' => $columnName, 'fieldName' => lcfirst(Container::camelize($columnName)), 'type' => $type);

            if ($type == 'string') {
                $question = new Question($questionHelper->getQuestion('Field length', 255), 255);
                $question->setValidator($lengthValidator);
                $data['length'] = $questionHelper->ask($input, $output, $question);
            }

            if ($type == 'one_to_many') {
                $question = new Question($questionHelper->getQuestion('Target Entity class', ''), '');
                $question->setAutocompleterValues($autocompleteEntities);
                $data['targetEntity'] = $questionHelper->ask($input, $output, $question);

                $question = new Question($questionHelper->getQuestion('Mapped by', ''), '');
                $mappedBy = $questionHelper->ask($input, $output, $question);
                if (empty($mappedBy) == false)
                    $data['mappedBy'] = $mappedBy;
            }

            if ($type == 'many_to_one') {
                $question = new Question($questionHelper->getQuestion('Target Entity class', ''), '');
                $question->setAutocompleterValues($autocompleteEntities);
                $data['targetEntity'] = $questionHelper->ask($input, $output, $question);

                $question = new Question($questionHelper->getQuestion('Inversed by', ''), '');
                $inversedBy = $questionHelper->ask($input, $output, $question);
                if (empty($inversedBy) == false)
                    $data['inversedBy'] = $inversedBy;

                $question = new Question($questionHelper->getQuestion('Join Column Name', ''), '');
                $joinColumnName = $questionHelper->ask($input, $output, $question);
                if (empty($joinColumnName) == false)
                    $data['joinColumn'] = array('name' => $joinColumnName, 'referencedColumnName' => 'id');
            }

            if ($type == 'one_to_one') {
                $question = new Question($questionHelper->getQuestion('Target Entity class', ''), '');
                $question->setAutocompleterValues($autocompleteEntities);
                $data['targetEntity'] = $questionHelper->ask($input, $output, $question);

                $question = new ConfirmationQuestion($questionHelper->getQuestion('Is Owning Side?', 'yes'), true);
                $isOwningSide = $questionHelper->ask($input, $output, $question);
                if ($isOwningSide == true) {
                    $question = new Question($questionHelper->getQuestion('Inversed By', ''), '');
                    $inversedBy = $questionHelper->ask($input, $output, $question);
                    if (empty($inversedBy) == false)
                        $data['inversedBy'] = $inversedBy;

                    $question = new Question($questionHelper->getQuestion('Join Column Name', ''), '');
                    $joinColumnName = $questionHelper->ask($input, $output, $question);
                    $data['joinColumn'] = array('name' => $joinColumnName, 'referencedColumnName' => 'id');
                    $data['isOwningSide'] = true;
                } else {
                    $question = new Question($questionHelper->getQuestion('Mapped By', ''), '');
                    $mappedBy = $questionHelper->ask($input, $output, $question);
                    if (empty($mappedBy) == false)
                        $data['mappedBy'] = $mappedBy;
                    $data['isOwningSide'] = false;
                }
            }

            if ($type == 'many_to_many') {
                $question = new Question($questionHelper->getQuestion('Target Entity class', ''), '');
                $question->setAutocompleterValues($autocompleteEntities);
                $data['targetEntity'] = $questionHelper->ask($input, $output, $question);

                $question = new ConfirmationQuestion($questionHelper->getQuestion('Is Owning Side?', 'yes'), true);
                $isOwningSide = $questionHelper->ask($input, $output, $question);
                if($isOwningSide == true){
                    $question = new Question($questionHelper->getQuestion('Inversed By', ''), '');
                    $inversedBy = $questionHelper->ask($input, $output, $question);
                    if (empty($inversedBy) == false)
                        $data['inversedBy'] = $inversedBy;

                    $question = new Question($questionHelper->getQuestion('Join Table Name', ''), '');
                    $joinTableName = $questionHelper->ask($input, $output, $question);
                    if (empty($joinTableName) == false)
                        $data['joinTable'] = array('name' => $joinTableName);

                    $data['isOwningSide'] = true;
                }else{
                    $question = new Question($questionHelper->getQuestion('Mapped By', ''), '');
                    $mappedBy = $questionHelper->ask($input, $output, $question);
                    if (empty($mappedBy) == false)
                        $data['mappedBy'] = $mappedBy;

                    $data['isOwningSide'] = false;
                }
            }

            $fields[$columnName] = $data;
        }

        return $fields;
    }

    protected function createGenerator()
    {
        return new DoctrineEntityGenerator($this->getContainer()->get('filesystem'), $this->getContainer()->get('doctrine'));
    }
}
