<?php
/**
 * For licensing information, please see the LICENSE file accompanied with this file.
 *
 * @author Gerard van Helden <drm@melp.nl>
 * @copyright 2012 Gerard van Helden <http://melp.nl>
 */

namespace Zicht\Tool\Plugin\Svn;

use \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

use \Zicht\Tool\Container\Container;
use \Zicht\Tool\Plugin as BasePlugin;

/**
 * SVN plugin configuration
 */
class Plugin extends BasePlugin
{
    /**
     * Appends SVN configuration options
     *
     * @param \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $rootNode
     * @return mixed|void
     */
    public function appendConfiguration(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->children()
                ->arrayNode('vcs')
                    ->children()
                        ->scalarNode('url')->end()
                        ->arrayNode('export')
                            ->children()
                                ->scalarNode('revfile')->isRequired()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @{inheritDoc}
     */
    public function setContainer(Container $container)
    {
        if (!$container->resolve(array('vcs', 'url'))) {
            $container->decl(
                array('vcs', 'url'),
                function (Container $c) {
                    $url = $c->helperExec('svn info | egrep \'^URL\' | awk \'{print $2}\'');
                    $content = rtrim(preg_replace('~trunk|branches/[^/]+$~', '', $url), "\n/");
                    return $content;
                }
            );
        }
        $container->method(
            array('vcs', 'abs'),
            function($container, $path) {
                return rtrim($container->resolve(array('vcs', 'url'), '/') . '/') . $path;
            }
        );
        $container->method(
            array('vcs', 'versionid'),
            function($container, $info) {
                if (
                    trim($info)
                    && preg_match('/^URL: (.*)/m', $info, $urlMatch)
                    && preg_match('/^Revision: (.*)/m', $info, $revMatch)
                ) {
                    $url = $urlMatch[1];
                    $rev = $revMatch[1];
                    $projectUrl = $container->resolve(array('vcs', 'url'));

                    if (substr($url, 0, strlen($projectUrl)) != $projectUrl) {
                        $err = "The project url {$projectUrl} does not match the VCS url {$url}\n";
                        $err .= "Maybe you need to relocate your working copy?";
                        throw new \UnexpectedValueException($err);
                    }

                    return ltrim(substr($url, strlen($projectUrl)), '/') . '@' . $rev;
                }
                return null;
            }
        );
        $container->decl(array('vcs', 'versions'), function($c) {
            $versions = explode(
                "\n",
                $c->helperExec(
                    sprintf(
                        '(svn ls %1$s/tags              | sed \'s!/$!!g\' | awk \'{print $1}\' )'
                            . '&&  (svn ls %1$s/branches    | sed \'s!/$!!g\' | awk \'{print "dev-"$1}\' )',
                        $c->resolve(array('vcs', 'url'))
                    )
                )
            );

            usort($versions, array('Zicht\Version\Version', 'isConform'));
            usort($versions, array('Zicht\Version\Version', 'compare'));
            return $versions;
        });
        $container->method(
            array('versionof'),
            function($container, $dir) {
                $info = @shell_exec('svn info ' . $dir . ' 2>&1');
                if (!$info && is_file($revFile = ($dir . '/' . $container->resolve(array('vcs', 'export', 'revfile'))))) {
                    $info = file_get_contents($revFile);
                }

                if ($info) {
                    return $container->call($container->resolve(array('vcs', 'versionid')), $info);
                } else {
                    return null;
                }
            }
        );
        $container->method(
            array('vcs', 'diff'),
            function($container, $left, $right, $verbose = false) {
                $left = $container->resolve(array('vcs', 'url')) . '/' . $left;
                $right = $container->resolve(array('vcs', 'url')) . '/' . $right;
                return sprintf('svn diff %s %s %s', $left, $right, ($verbose ? '' : '--summarize'));
            }
        );
        $container->decl(
            array('vcs', 'current'),
            function($container) {
                return $container->call($container->get('versionof'), $container->resolve('cwd'));
            }
        );
        $container->fn(
            array('svn', 'wc', 'lastchange'),
            function ($dir) {
                $data = shell_exec('svn status -v ' . escapeshellarg($dir) . ' --xml');

                $status = new \SimpleXMLElement($data);

                $max = 0;
                $file = null;
                foreach ($status->target->entry as $entry) {
                    $rev = (int)(string)$entry->{'wc-status'}['revision'];
                    if ($rev > $max) {
                        $file = ltrim(str_replace(realpath($dir), '', realpath($entry['path'])), '/');
                        $max = $rev;
                    }
                }

                return array($max, $file);
            }
        );

        $container->decl(
            array('vcs', 'current'),
            function($container) {
                $ret = $container->call($container->get('versionof'), $container->resolve('cwd'));

                list($lastRev, $file) = $container->call($container->get(array('svn', 'wc', 'lastchange')), $container->resolve('cwd'));
                list(,$rev) = explode('@', $ret);
                if ($lastRev > $rev) {
                    trigger_error(
                        E_USER_WARNING,
                        sprintf(
                            "Mixed revision working copy.\n"
                            . "The last revision number is <info>@{$lastRev}</info>\n"
                            . "Your working copy root is   <info>@{$rev}</info>.\n"
                            . "You should consider updating your working copy.\n"
                        )
                    );
                }

                return $ret;
            }
        );
    }
}
