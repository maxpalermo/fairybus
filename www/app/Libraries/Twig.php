<?php

/**
 * Copyright since 2026 Massimiliano Palermo
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to maxx.palermo@gmail.com so we can send you a copy immediately.
 *
 * @author    Massimiliano Palermo <maxx.palermo@gmail.com>
 * @copyright Since 2026 Massimiliano Palermo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace App\Libraries;

use Config\Services;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class Twig
{
    private static ?Environment $environment = null;

    public static function environment(): Environment
    {
        if (self::$environment === null) {
            $paths = [
                APPPATH . 'Views',
                APPPATH . 'Modules/FairyBus/Views',
            ];

            $loader = new FilesystemLoader($paths);

            $options = [
                'cache' => WRITEPATH . 'twig/cache',
                'auto_reload' => ENVIRONMENT === 'development',
                'debug' => ENVIRONMENT === 'development',
            ];

            if (!is_dir($options['cache'])) {
                mkdir($options['cache'], 0775, true);
            }

            self::$environment = new Environment($loader, $options);

            self::registerFunctions(self::$environment);
        }

        return self::$environment;
    }

    private static function registerFunctions(Environment $twig): void
    {
        $twig->addFunction(new \Twig\TwigFunction('base_url', static fn(string $path = '') => \base_url($path)));
        $twig->addFunction(new \Twig\TwigFunction('site_url', static fn(string $path = '') => \site_url($path)));
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn(string $path) => \base_url('assets/' . ltrim($path, '/'))));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', static function (): array {
            $security = Services::security();

            return [
                'name' => $security->getTokenName(),
                'value' => $security->getHash(),
            ];
        }));
    }

    public static function render(string $template, array $data = []): string
    {
        return self::environment()->render($template, $data);
    }
}
