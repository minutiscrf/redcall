<?php

namespace Bundles\SandboxBundle\Controller;

use Bundles\SandboxBundle\Base\BaseController;
use Bundles\SandboxBundle\Services\AnimGif;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

class SpinnerController extends BaseController
{
    /**
     * @Route("/spinner", name="spinner")
     * @Template
     */
    public function index(Request $request)
    {
        throw $this->createNotFoundException('disabled for the hackathon');

        $form = $this
            ->createFormBuilder([
                'splits' => 12,
                'mixer'  => '#e9ecef',
                'width'  => 100,
                'speed'  => 5,
            ])
            ->add('file', FileType::class, [
                'label' => false,
            ])
            ->add('splits', IntegerType::class, [
                'label'       => 'sandbox.spinner.splits',
                'constraints' => [
                    new NotBlank(),
                    new Range(['min' => 2, 'max' => 100]),
                ],
            ])
            ->add('mixer', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                    new Regex('/^#[0-9a-f]{6}$/'),
                ],
            ])
            ->add('speed', IntegerType::class, [
                'constraints' => [
                    new NotBlank(),
                    new Range(['min' => 1, 'max' => 100]),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'base.button.submit',
            ])
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data   = $form->getData();
            $images = $this->createSplits($data);
            $result = $this->createResult($data);
        }

        return [
            'form'   => $form->createView(),
            'images' => $images ?? null,
            'result' => $result ?? null,
        ];
    }

    private function createSplits(array $data) : array
    {
        $splits = [];

        $contents = file_get_contents($data['file']);
        $step     = 100 / $data['splits'];

        for ($opacity = $step; $opacity <= 100; $opacity += $step) {
            $image = $this->createImage($contents, $opacity, $data);
            ob_start();
            imagegif($image);
            $bytes    = ob_get_clean();
            $splits[] = base64_encode($bytes);
        }

        return $splits;
    }

    private function createResult(array $data) : string
    {
        $images   = [];
        $contents = file_get_contents($data['file']);
        $step     = 100 / $data['splits'];

        for ($opacity = $step; $opacity <= 100; $opacity += $step) {
            $images[] = $this->createImage($contents, $opacity, $data);
        }
        for ($i = count($images) - 2; $i > 0; $i--) {
            $images[] = $images[$i];
        }

        $anim = new AnimGif();
        $anim->create($images, $data['speed']);

        $bytes = $anim->get();

        return base64_encode($bytes);
    }

    private function createImage(string $contents, float $opacity, array $options)
    {
        $image = imagecreatefromstring($contents);

        imagealphablending($image, false);

        // Make background transparent (considering that first pixel at top left is background)
        $color  = imagecolorat($image, 0, 0);
        $r      = ($color >> 16) & 0xFF;
        $g      = ($color >> 8) & 0xFF;
        $b      = $color & 0xFF;
        $transp = imagecolorallocatealpha($image, $r, $g, $b, 127);
        imagecolortransparent($image, $transp);

        // On a PNG, the following code is enough:
        //
        //        imagesavealpha($image, true);
        //        $transparency = 1 - $opacity / 100;
        //        imagefilter($image, IMG_FILTER_COLORIZE, 0, 0, 0, 127 * $transparency);

        // But on GIFs, transparency is a single color, so we'll mix the color with white instead.
        // White (mixing color) = opacity 0%
        // original color at pixel = opacity 100%

        $mixer  = base_convert(substr($options['mixer'], 1), 16, 10);
        $rMixer = ($mixer >> 16) & 0xFF;
        $gMixer = ($mixer >> 8) & 0xFF;
        $bMixer = $mixer & 0xFF;

        $width  = imagesx($image);
        $height = imagesy($image);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $color = imagecolorat($image, $x, $y);
                if ($color == $transp) {
                    continue;
                }

                // Original color
                $r = ($color >> 16) & 0xFF;
                $g = ($color >> 8) & 0xFF;
                $b = $color & 0xFF;

                // Mixed color
                $r = (($r * $opacity) / 100) + (($rMixer * (100 - $opacity)) / 100);
                $g = (($g * $opacity) / 100) + (($gMixer * (100 - $opacity)) / 100);
                $b = (($b * $opacity) / 100) + (($bMixer * (100 - $opacity)) / 100);

                $mixed = imagecolorallocate($image, $r, $g, $b);
                imagesetpixel($image, $x, $y, $mixed);
            }
        }

        return $image;
    }
}