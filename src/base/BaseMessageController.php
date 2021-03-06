<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\base;

use Craft;
use craft\web\Controller;
use craft\web\View;
use yii\web\Response;

/**
 * BaseMessageController
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.10.0
*/
abstract class BaseMessageController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Renders a message template.
     *
     * @param string|null $template
     * @param array|null $variables
     * @return Response
     */
    public function renderMessageTemplate(string $template = null, array $variables = []): Response
    {
        // If template was not defined or does not exist
        if (empty($template) || !Craft::$app->getView()->doesTemplateExist($template)) {
            // Use message template
            $template = 'campaign/message';

            // Set template mode to CP
            Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        }

        return parent::renderTemplate($template, $variables);
    }
}
