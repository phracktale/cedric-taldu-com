<?php

declare(strict_types=1);

namespace APress\Core\Ui;

use APress\Core\Container;
use APress\Core\Content\ContentType;
use APress\Core\Content\Entry;
use APress\Core\Extension\EditorPanel;
use APress\Core\Extension\Hooks;
use APress\Core\LoggerInterface;
use APress\Core\LogLevel;
use Throwable;

/**
 * Rend les panneaux des extensions pour une entrée.
 *
 * Un panneau en panne (service absent, exception) est journalisé et omis :
 * une extension ne doit jamais empêcher d'éditer un contenu.
 */
final class EditorPanels
{
    public function __construct(
        private readonly Hooks $hooks,
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<string>
     */
    public function render(ContentType $type, Entry $entry): array
    {
        $html = [];
        foreach ($this->hooks->editorPanels() as $id) {
            try {
                $panel = $this->container->get($id);
                if (!$panel instanceof EditorPanel) {
                    throw new \LogicException(sprintf('« %s » n\'est pas un EditorPanel.', $id));
                }
                $rendered = $panel->render($type, $entry);
            } catch (Throwable $exception) {
                $this->logger->log(LogLevel::Warning, 'extension.panel_failed', ['panel' => $id, 'error' => $exception->getMessage()]);
                continue;
            }
            if ($rendered !== null && $rendered !== '') {
                $html[] = $rendered;
            }
        }

        return $html;
    }
}
