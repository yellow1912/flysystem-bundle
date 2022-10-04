<?php

/*
 * This file is part of the flysystem-bundle project.
 *
 * (c) Titouan Galopin <galopintitouan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace League\FlysystemBundle\DependencyInjection\Compiler;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\FilesystemWriter;
use League\FlysystemBundle\Adapter\AdapterDefinitionFactory;
use League\FlysystemBundle\Adapter\Builder\AbstractAdapterDefinitionBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\VarDumper\VarDumper;

/**
 * @author Titouan Galopin <galopintitouan@gmail.com>
 *
 * @internal
 */
class FactoryPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        $this->createStoragesDefinitions($this->getConfiguration($container->getExtensionConfig('flysystem')), $container);
    }

    /**
     * @throws \Exception
     */
    private function createStoragesDefinitions(array $config, ContainerBuilder $container)
    {
        $definitionFactory = new AdapterDefinitionFactory();

        foreach ($config['storages'] as $storageName => $storageConfig) {
            // If the storage is a lazy one, it's resolved at runtime
            if ('lazy' === $storageConfig['adapter']) {
                $container->setDefinition($storageName, $this->createLazyStorageDefinition($storageName, $storageConfig['options']));

                // Register named autowiring alias
                $container->registerAliasForArgument($storageName, FilesystemOperator::class, $storageName)->setPublic(false);
                $container->registerAliasForArgument($storageName, FilesystemReader::class, $storageName)->setPublic(false);
                $container->registerAliasForArgument($storageName, FilesystemWriter::class, $storageName)->setPublic(false);

                continue;
            }

            // Create adapter definition
            if ($adapter = $definitionFactory->createDefinition($storageConfig['adapter'], $storageConfig['options'])) {
                // Native adapter
                $container->setDefinition('flysystem.adapter.' . $storageName, $adapter)->setPublic(false);
            } else {
                // Custom adapter
                if ($container->hasDefinition($storageConfig['adapter'])) {
                    $container->setAlias('flysystem.adapter.' . $storageName, $storageConfig['adapter'])->setPublic(false);
                } else {
                    // it's possible to pass a fully qualified class name to adapter
                    if (class_exists($storageConfig['adapter'])) {
                        // in this case, the adapter can be a FilesystemAdapter
                        if ($storageConfig['adapter'] instanceof FilesystemAdapter) {
                            $definition = new Definition();
                            $definition->setClass($storageConfig['adapter']);
                            foreach ($storageConfig['options'] as $k => $v) {
                                $definition->setArgument('$' . $k, $v);
                            }
                            $container->setDefinition('flysystem.adapter.' . $storageName, $definition)->setPublic(false);
//                            $container->setAlias('flysystem.adapter.' . $storageName, $storageConfig['adapter'])->setPublic(false);
                        } elseif (is_subclass_of($storageConfig['adapter'], AbstractAdapterDefinitionBuilder::class)) {
                            // alternatively, it can also be a definition builder
                            $builder = new $storageConfig['adapter'];
                            $container->setDefinition('flysystem.adapter.' . $storageName, $builder->createDefinition($storageConfig['options']))->setPublic(false);
//                            $container->setAlias('flysystem.adapter.' . $storageName, $builder->getName())->setPublic(false);
                        } else {
                            throw new \Exception(sprintf('Custom adapter `%s` is neither a FilesystemAdapter nor a AdapterDefinitionBuilder class', $storageConfig['adapter']));
                        }
                    } else {
                        throw new \Exception(sprintf('Custom adapter `%s` is neither a service nor a valid class', $storageConfig['adapter']));
                    }
                }
            }

            // Create storage definition
            $container->setDefinition(
                $storageName,
                $this->createStorageDefinition($storageName, new Reference('flysystem.adapter.' . $storageName), $storageConfig)
            );

            // Register named autowiring alias
            $container->registerAliasForArgument($storageName, FilesystemOperator::class, $storageName)->setPublic(false);
            $container->registerAliasForArgument($storageName, FilesystemReader::class, $storageName)->setPublic(false);
            $container->registerAliasForArgument($storageName, FilesystemWriter::class, $storageName)->setPublic(false);
        }
    }

    private function createLazyStorageDefinition(string $storageName, array $options)
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired('source');
        $resolver->setAllowedTypes('source', 'string');

        $definition = new Definition(FilesystemOperator::class);
        $definition->setPublic(false);
        $definition->setFactory([new Reference('flysystem.adapter.lazy.factory'), 'createStorage']);
        $definition->setArgument(0, $resolver->resolve($options)['source']);
        $definition->setArgument(1, $storageName);
        $definition->addTag('flysystem.storage', ['storage' => $storageName]);

        return $definition;
    }

    private function createStorageDefinition(string $storageName, Reference $adapter, array $config)
    {
        $definition = new Definition(Filesystem::class);
        $definition->setPublic(false);
        $definition->setArgument(0, $adapter);
        $definition->setArgument(1, [
            'visibility' => $config['visibility'],
            'directory_visibility' => $config['directory_visibility'],
            'case_sensitive' => $config['case_sensitive'],
            'disable_asserts' => $config['disable_asserts'],
        ]);
        $definition->addTag('flysystem.storage', ['storage' => $storageName]);

        return $definition;
    }

    private function getConfiguration(array $configs, ConfigurationInterface $configuration = null)
    {
        if (null === $configuration) {
            if (preg_match('#(.*)\\\\(.*)Compiler\\\\#', \get_class($this), $match)) {
                $configuration = $match[1] . '\\Configuration';
            }

            $configuration = new $configuration();
        }

        $processor = new Processor();

        return $processor->processConfiguration($configuration, $configs);
    }
}
