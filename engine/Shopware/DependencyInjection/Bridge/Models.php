<?php

namespace Shopware\DependencyInjection\Bridge;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Driver\DriverChain;
use Shopware\Components\Model\CategoryDenormalization;
use Shopware\Components\Model\CategorySubscriber;
use Shopware\Components\Model\Configuration;
use Shopware\Components\Model\EventSubscriber;
use Shopware\Components\Model\ModelManager;
use Shopware\Models\Order\OrderHistorySubscriber;

class Models
{

    protected $config;
    protected $modelPath;
    protected $loader;
    protected $eventManager;
    protected $db;
    protected $resourceLoader;
    protected $kernelRootDir;

    public function __construct(
        Configuration $config,
        $modelPath,
        \Enlight_Loader $loader,
        \Enlight_Event_EventManager $eventManager,
        \Enlight_Components_Db_Adapter_Pdo_Mysql $db,
        $resourceLoader,
        $kernelRootDir
    ) {
        $this->config = $config;
        $this->modelPath = $modelPath;
        $this->loader = $loader;
        $this->eventManager = $eventManager;
        $this->db = $db;
        $this->resourceLoader = $resourceLoader;
        $this->kernelRootDir = $kernelRootDir;
    }

    public function factory()
    {
        // register standard doctrine annotations
        AnnotationRegistry::registerFile(
            'Doctrine/ORM/Mapping/Driver/DoctrineAnnotations.php'
        );

        // register symfony validation annotations
        AnnotationRegistry::registerAutoloadNamespace(
            'Symfony\Component\Validator\Constraint',
            realpath($this->kernelRootDir . '/vendor/symfony/validator')
        );

        $cachedAnnotationReader = $this->config->getAnnotationsReader();

        $annotationDriver = new AnnotationDriver(
            $cachedAnnotationReader,
            array(
                $this->modelPath,
                $this->config->getAttributeDir(),
            )
        );

        $this->loader->registerNamespace(
            'Shopware\Models\Attribute',
            $this->config->getAttributeDir()
        );

        // create a driver chain for metadata reading
        $driverChain = new DriverChain();

        // register annotation driver for our application
        $driverChain->addDriver($annotationDriver, 'Shopware\\Models\\');
        $driverChain->addDriver($annotationDriver, 'Shopware\\CustomModels\\');

        $this->resourceLoader->registerResource('ModelAnnotations', $annotationDriver);

        $this->config->setMetadataDriverImpl($driverChain);

        // Create event Manager
        $eventManager = new EventManager();

        // Create new shopware event subscriber to handle the entity lifecycle events.
        $lifeCycleSubscriber = new EventSubscriber(
            $this->$eventManager
        );
        $eventManager->addEventSubscriber($lifeCycleSubscriber);

        $categorySubscriber = new CategorySubscriber();

        $this->resourceLoader->registerResource('CategorySubscriber', $categorySubscriber);
        $eventManager->addEventSubscriber($categorySubscriber);

        $eventManager->addEventSubscriber(new OrderHistorySubscriber());

        $categoryDenormalization = new CategoryDenormalization(
            $this->db->getConnection()
        );

        $this->resourceLoader->registerResource('CategoryDenormalization', $categoryDenormalization);

        // now create the entity manager and use the connection
        // settings we defined in our application.ini
        $conn = DriverManager::getConnection(
            array('pdo' => $this->db->getConnection()),
            $this->config,
            $eventManager
        );

        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('bit', 'boolean');

        $entityManager = ModelManager::create(
            $conn, $this->config, $eventManager
        );

        return $entityManager;
    }
}
