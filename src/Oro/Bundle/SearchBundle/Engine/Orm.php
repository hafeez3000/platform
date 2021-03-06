<?php

namespace Oro\Bundle\SearchBundle\Engine;

use Doctrine\ORM\EntityManager;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;

use JMS\JobQueueBundle\Entity\Job;

use Oro\Bundle\SearchBundle\Query\Query;
use Oro\Bundle\SearchBundle\Query\Result\Item as ResultItem;
use Oro\Bundle\SearchBundle\Entity\Item;
use Oro\Bundle\SearchBundle\Engine\Indexer;
use Oro\Bundle\SearchBundle\Engine\ObjectMapper;

class Orm extends AbstractEngine
{
    /**
     * @var \Symfony\Component\DependencyInjection\ContainerInterface
     */
    protected $container;

    /**
     * @var \Oro\Bundle\SearchBundle\Entity\Repository\SearchIndexRepository
     */
    protected $searchRepo;

    /**
     * @var \JMS\JobQueueBundle\Entity\Repository\JobRepository
     */
    protected $jobRepo;

    /**
     * @var \Oro\Bundle\SearchBundle\Engine\ObjectMapper
     */
    protected $mapper;

    public function __construct(EntityManager $em, ContainerInterface $container, ObjectMapper $mapper, $logQueries)
    {
        $this->container = $container;
        $this->em = $em;
        $this->mapper = $mapper;
        $this->logQueries = $logQueries;
    }

    /**
     * Reload search index
     *
     * @return int Count of index records
     */
    public function reindex()
    {
        //clear old index
        $this->clearSearchIndex();

        //index data by mapping config
        $recordsCount = 0;
        $entities = $this->mapper->getEntities();
        foreach ($entities as $entityName) {
            $entityData = $this->em->getRepository($entityName)->findAll();
            foreach ($entityData as $entity) {
                if ($this->save($entity, true) !== false) {
                    $recordsCount++;
                }
            }
        }

        return $recordsCount;
    }

    /**
     * Delete record from index
     *
     * @param object $entity   Entity to be removed from index
     * @param bool   $realtime [optional] Perform immediate insert/update to
     *                              search attributes table(s). True by default.
     * @return bool|int Index item id on success, false otherwise
     */
    public function delete($entity, $realtime = true)
    {
        $item = $this->getIndexRepo()->findOneBy(
            array(
                'entity'   => get_class($entity),
                'recordId' => $entity->getId()
            )
        );

        if ($item) {
            $id = $item->getId();

            if ($realtime) {
                $this->em->remove($item);
            } else {
                $item->setChanged(!$realtime);

                $this->reindexJob();
            }

            $this->em->flush();

            return $id;
        }

        return false;
    }

    /**
     * Insert or update record
     *
     * @param object $entity   New/updated entity
     * @param bool   $realtime [optional] Perform immediate insert/update to
     *                              search attributes table(s). True by default.
     * @return bool|int Index item id on success, false otherwise
     */
    public function save($entity, $realtime = true)
    {
        $data = $this->mapper->mapObject($entity);
        $name = get_class($entity);

        if (count($data)) {
            $item = $this->getIndexRepo()->findOneBy(
                array(
                    'entity'   => $name,
                    'recordId' => $entity->getId()
                )
            );

            if (!$item) {
                $item = new Item();

                $entityConfig = $this->mapper->getEntityConfig(get_class($entity));
                if ($entityConfig) {
                    $alias = $entityConfig['alias'];
                } else {
                    $alias = get_class($entity);
                }

                $item->setEntity($name)
                     ->setRecordId($entity->getId())
                     ->setAlias($alias);
            }

            $item->setChanged(!$realtime);

            if ($realtime) {
                $item->setTitle($this->getEntityTitle($entity))
                    ->saveItemData($data);
            } else {
                $this->reindexJob();
            }

            $this->em->persist($item);
            $this->em->flush();

            return $item->getId();
        }

        return false;
    }

    /**
     * Search query with query builder
     *
     * @param \Oro\Bundle\SearchBundle\Query\Query $query
     *
     * @return array
     */
    protected function doSearch(Query $query)
    {
        $results = array();
        $searchResults = $this->getIndexRepo()->search($query);
        if (($query->getMaxResults() > 0 || $query->getFirstResult() > 0)) {
            $recordsCount = $this->getIndexRepo()->getRecordsCount($query);
        } else {
            $recordsCount = count($searchResults);
        }
        if ($searchResults) {
            foreach ($searchResults as $item) {
                if (is_array($item)) {
                    $item = $item['item'];
                }
                /** @var $item \Oro\Bundle\SearchBundle\Entity\Item  */
                $results[] = new ResultItem(
                    $this->em,
                    $item->getEntity(),
                    $item->getRecordId(),
                    $item->getTitle(),
                    $this->getEntityUrl(
                        $this->em->getRepository($item->getEntity())->find($item->getRecordId())
                    ),
                    $item->getRecordText(),
                    $this->mapper->getEntityConfig($item->getEntity())
                );
            }
        }

        return array(
            'results' => $results,
            'records_count' => $recordsCount
        );
    }

    /**
     * Add reindex task to job queue if it has not been added earlier
     */
    protected function reindexJob()
    {
        // check if reindex task has not been added earlier
        $command = 'oro:search:index';
        $currJob = $this->em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.command = :command AND j.state <> :state")
            ->setParameter('command', $command)
            ->setParameter('state', Job::STATE_FINISHED)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if (!$currJob) {
            $job = new Job($command);

            $this->em->persist($job);
        }
    }

    /**
     * Get search index repository
     *
     * @return \Oro\Bundle\SearchBundle\Entity\Repository\SearchIndexRepository
     */
    protected function getIndexRepo()
    {
        if (!is_object($this->searchRepo)) {
            $this->searchRepo = $this->em->getRepository('OroSearchBundle:Item');
            $this->searchRepo->setDriversClasses($this->container->getParameter('oro_search.engine_orm'));
        }

        return $this->searchRepo;
    }

    /**
     * Get job repository
     *
     * @return \JMS\JobQueueBundle\Entity\Repository\JobRepository
     */
    protected function getJobRepo()
    {
        if (!is_object($this->jobRepo)) {
            $this->jobRepo = $this->em->getRepository('JMSJobQueueBundle:Job');
        }

        return $this->jobRepo;
    }

    /**
     * Get url for entity
     *
     * @param object $entity
     *
     * @return string
     */
    protected function getEntityUrl($entity)
    {
        if ($this->mapper->getEntityMapParameter(get_class($entity), 'route')) {
            $routeParameters = $this->mapper->getEntityMapParameter(get_class($entity), 'route');
            $routeData = array();
            if (isset($routeParameters['parameters']) && count($routeParameters['parameters'])) {
                foreach ($routeParameters['parameters'] as $parameter => $field) {
                    $routeData[$parameter] = $this->mapper->getFieldValue($entity, $field);
                }
            }

            return $this->container->get('router')->generate(
                $routeParameters['name'],
                $routeData,
                true
            );
        }

        return '';
    }

    /**
     * Get entity string
     *
     * @param object $entity
     *
     * @return string
     */
    protected function getEntityTitle($entity)
    {
        if ($this->mapper->getEntityMapParameter(get_class($entity), 'title_fields')) {
            $fields = $this->mapper->getEntityMapParameter(get_class($entity), 'title_fields');
            $title = array();
            foreach ($fields as $field) {
                $title[] = $this->mapper->getFieldValue($entity, $field);
            }
        } else {
            $title = array((string) $entity);
        }

        return implode(' ', $title);
    }

    /**
     * Truncate search tables
     */
    protected function clearSearchIndex()
    {
        $connection = $this->em->getConnection();
        $dbPlatform = $connection->getDatabasePlatform();
        $connection->beginTransaction();
        try {
            $connection->query('SET FOREIGN_KEY_CHECKS=0');
            $this->truncate($dbPlatform, $connection, 'OroSearchBundle:Item');
            $this->truncate($dbPlatform, $connection, 'OroSearchBundle:IndexDecimal');
            $this->truncate($dbPlatform, $connection, 'OroSearchBundle:IndexText');
            $this->truncate($dbPlatform, $connection, 'OroSearchBundle:IndexInteger');
            $this->truncate($dbPlatform, $connection, 'OroSearchBundle:IndexDatetime');
            $connection->query('SET FOREIGN_KEY_CHECKS=1');
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollback();
        }

    }

    /**
     * Truncate query for table
     *
     * @param $dbPlatform
     * @param $connection
     * @param $table
     */
    protected function truncate($dbPlatform, $connection, $table)
    {
        $cmd = $this->em->getClassMetadata($table);
        $q = $dbPlatform->getTruncateTableSql($cmd->getTableName());
        $connection->executeUpdate($q);
    }
}
