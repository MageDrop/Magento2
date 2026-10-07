<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml\Product;

use Magento\Catalog\Block\Adminhtml\Product\Helper\Form\Gallery\Content;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The admin gallery after "Load from Release" (MediaGallery::loadIntoEntity()): images the
 * release adds are ".tmp" uploads, shown from the tmp media folder like a fresh upload, and
 * images it removes stay out of the visible gallery (the form data still posts them removed).
 */
class GalleryJsonPlugin
{
    public function __construct(
        private MediaConfig $mediaConfig,
        private Filesystem $filesystem,
        private Json $json
    ) {
    }

    public function afterGetImagesJson(Content $subject, $result)
    {
        if (!is_string($result) || $result === '[]' || (!str_contains($result, '.tmp') && !str_contains($result, '"removed":"1"'))) {
            return $result;
        }
        $images = $this->json->unserialize($result);
        if (!is_array($images)) {
            return $result;
        }

        $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $out = [];
        foreach ($images as $image) {
            if (!is_array($image) || (string) ($image['removed'] ?? '') === '1') {
                continue;
            }
            $file = (string) ($image['file'] ?? '');
            if (str_ends_with($file, '.tmp')) {
                $base = substr($file, 0, -4);
                $image['url'] = $this->mediaConfig->getTmpMediaUrl($base);
                try {
                    $image['size'] = $mediaDirectory->stat($this->mediaConfig->getTmpMediaPath($base))['size'];
                } catch (\Throwable) {
                    $image['size'] = 0;
                }
            }
            $out[] = $image;
        }

        return $this->json->serialize($out);
    }
}
