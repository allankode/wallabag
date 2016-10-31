<?php

namespace Wallabag\CoreBundle\Event\Subscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;
use Wallabag\CoreBundle\Helper\DownloadImages;
use Wallabag\CoreBundle\Entity\Entry;
use Doctrine\ORM\EntityManager;
use Craue\ConfigBundle\Util\Config;

class DownloadImagesSubscriber implements EventSubscriber
{
    private $configClass;
    private $downloadImages;
    private $logger;

    /**
     * We inject the class instead of the service otherwise it generates a circular reference with the EntityManager.
     * So we build the service ourself when we got the EntityManager (in downloadImages).
     */
    public function __construct(DownloadImages $downloadImages, $configClass, LoggerInterface $logger)
    {
        $this->downloadImages = $downloadImages;
        $this->configClass = $configClass;
        $this->logger = $logger;
    }

    public function getSubscribedEvents()
    {
        return array(
            'prePersist',
            'preUpdate',
        );
    }

    /**
     * In case of an entry has been updated.
     * We won't update the content field if it wasn't updated.
     *
     * @param LifecycleEventArgs $args
     */
    public function preUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();

        if (!$entity instanceof Entry) {
            return;
        }

        $config = new $this->configClass();
        $config->setEntityManager($args->getEntityManager());

        if (!$config->get('download_images_enabled')) {
            return;
        }

        // field content has been updated
        if ($args->hasChangedField('content')) {
            $html = $this->downloadImages($config, $entity);

            if (false !== $html) {
                $args->setNewValue('content', $html);
            }
        }

        // field preview picture has been updated
        if ($args->hasChangedField('previewPicture')) {
            $previewPicture = $this->downloadPreviewImage($config, $entity);

            if (false !== $previewPicture) {
                $entity->setPreviewPicture($previewPicture);
            }
        }
    }

    /**
     * When a new entry is saved.
     *
     * @param LifecycleEventArgs $args
     */
    public function prePersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();

        if (!$entity instanceof Entry) {
            return;
        }

        $config = new $this->configClass();
        $config->setEntityManager($args->getEntityManager());

        if (!$config->get('download_images_enabled')) {
            return;
        }

        // update all images inside the html
        $html = $this->downloadImages($config, $entity);
        if (false !== $html) {
            $entity->setContent($html);
        }

        // update preview picture
        $previewPicture = $this->downloadPreviewImage($config, $entity);
        if (false !== $previewPicture) {
            $entity->setPreviewPicture($previewPicture);
        }
    }

    /**
     * Download all images from the html.
     *
     * @todo If we want to add async download, it should be done in that method
     *
     * @param Config $config
     * @param Entry  $entry
     *
     * @return string|false False in case of async
     */
    public function downloadImages(Config $config, Entry $entry)
    {
        $this->downloadImages->setWallabagUrl($config->get('wallabag_url'));

        return $this->downloadImages->processHtml(
            $entry->getContent(),
            $entry->getUrl()
        );
    }

    /**
     * Download the preview picture.
     *
     * @todo If we want to add async download, it should be done in that method
     *
     * @param Config $config
     * @param Entry  $entry
     *
     * @return string|false False in case of async
     */
    public function downloadPreviewImage(Config $config, Entry $entry)
    {
        $this->downloadImages->setWallabagUrl($config->get('wallabag_url'));

        return $this->downloadImages->processSingleImage(
            $entry->getPreviewPicture(),
            $entry->getUrl()
        );
    }
}
