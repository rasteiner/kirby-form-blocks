<?php

namespace microman;

use Kirby\Cms\Block;
use Kirby\Toolkit\I18n;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;
use Kirby\Filesystem\F;
use Kirby\Exception\Exception;

class Form extends Block
{

    /**
     * Formfields
     *
     * @var \Microman\FormFields
     */
    protected $fields;

    /**
     * Errormessage - if occur
     *
     * @var string
     */
    protected $error;

    /**
     * Page that stores the formdata
     *
     * @var \Kirby\Cms\Page
     */
    protected $requestPage;

    /**
     * Creates new form block
     *
     * @param array $params
     */
    public function __construct(array $params)
    {
        parent::__construct($this->setDefault($params));

        //Hands away from panel!
        if (preg_match('(api|panel)', $_SERVER['REQUEST_URI']) > 0) {
            return false;
            //$this->validateBackend($this->formfields()->toBlocks()->toArray());
        }

        $this->fields = new FormFields($this->formfields()->toBlocks()->toArray(), $this->parent(), $this->id());

        //Resolve Honeypot
        if (!$this->fields->checkHoneypot($this->honeypotId())) {
            $this->setError("Trapped into honeypot");
        };

        $this->runProcess();
    }

    /**
     * Set default values to new form block (Run if Block create)
     *
     * @param string $path
     *
     * @return array
     */
    private function getDefault(string $path, string $postfix = ""): array
    {
        $filename = "formblock_default";

        if ($out = F::read($path . $filename . $postfix)){
            return json_decode($out, true);
        }

        if ($out = F::read($path . $filename . '.json')) {
            return json_decode($out, true);
        }

        return [];
    }

    /**
     * Set default values to new form block (Run if Block create)
     *
     * @param array $params
     *
     * @return array
     */
    private function setDefault(array $params): array
    {
        if (!isset($params['id'])) {

            $postfix = ".json";

            if (site()->kirby()->multilang()) {
                $postfix =  "_" . site()->kirby()->language()->code() . ".json";
            }

            if(count($defaults = $this->getDefault(site()->kirby()->root('config') . "/", $postfix)) == 0){
                $defaults = $this->getDefault(__DIR__ . "/../config/", $postfix);
            };

            if (!isset($defaults[0]['content'])) {
                throw new Exception("Getting defaults failed. Check formblock_default*.json in config folder.");
            }

            $params['content'] =  $defaults[0]['content'];
        }

        return $params;
    }

    /**
     * Validate Backend
     *
     * @param array $formdata
     *
     * @return bool
     */
    private function validateBackend (array $formdata): bool
    {
        $slugs = [];

        foreach ($formdata as $field) {
            array_push($slugs, $field['content']['slug']);
        }

        return true;

    }

    /**********************/
    /** Formdata Methods **/
    /**********************/

    /**
     * Parse to String if needed
     *
     * @param mixed $value
     *
     * @return string
     */
    private function parseString($value): string
    {
        if (!(is_null($value))) {
            return (is_string($value)) ? $value : $value->value();
        }

        return null;
    }

    /**
     * Formfield by Name
     *
     * @param string $slug Name of the formfield (returns formfield object)
     * @param string|array $attrs Specific Value (returns array with specific value)
     *
     * @return array|object
     */
    public function fieldByName(string $slug, $attrs= NULL)
    {
        if (is_null($attrs)) {
            return $this->fields()->$slug();
        }

        if ($field = $this->fields->$slug()) {
            if (!is_array($attrs)) {
                return $this->parseString($field->$attrs());
            } else {
                $result = [];
                foreach ($attrs as $attr) {
                    $result[$attr] = $this->parseString($field->$attr());
                }
                return $result;
            }
        }
        return null;
    }

    /**
     * Formfields as Array
     *
     * @param string|null $attrs Set attribute in array (instead field object)
     *
     * @return array|object
     */
    public function fields($attrs = NULL)
    {
        if (is_null($attrs)) {
            return $this->fields;
        }
        $fields = [];
        foreach ($this->fields() as $field) {
            $fieldSlug = $field->slug()->toString();
            $fields[$fieldSlug] = $this->fieldByName($fieldSlug, $attrs);
        }
        return $fields;
    }

    /**
     * Get formdata with custom Placeholder
     *
     * @param string $attr Defines which atribute (value/label) of the placeholder should returned
     *
     * @return array
     */
    public function fieldsWithPlaceholder($attr = 'value'): array
    {
        $fields = [];
        foreach (option('microman.formblock.placeholders') as $placeholder => $item) {

            if (!isset($item['value']) || !($item['value'] instanceof \Closure)) {
                throw new Exception("Check microman.formblock.placeholders.$placeholder in config.");
            }
            $item['value'] = $item['value']($this->fields);
            $fields[$placeholder] = $attr ? $item[$attr] : $item;
        }

        return array_merge($this->fields($attr), $fields);
    }

    /**
     * Get honeypot name
     *
     * @return string
     */
    public function honeypotId(): string
    {
        $out = option('microman.formblock.honeypot_variants');

        foreach ($this->fields() as $field) {
            $out = array_diff($out, [$field->autofill(), $field->slug()]);
        }

        return count($out) > 0 ? array_values($out)[0] : "honeypot";
    }

    /************************/
    /** Validation Methods **/
    /************************/

    /**
     * Check if form is filled
     *
     * @return bool
     */
    public function isFilled(): bool
    {
        return $this->fields->isFilled();
    }

    /**
     * Check if all field filled right
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->fields->isValid();
    }

    /**
     * Check if error occurs
     *
     * @return bool
     */
    public function isFatal(): bool
    {
        return !empty($this->error);
    }

    /**
     * Check if request send successfully
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->isFilled() && $this->isValid() && !$this->isFatal();
    }

    /**
     * Get error fields
     *
     * @param string|NULL $separator Unset returns Array
     * @return string|array
     */
    public function errorFields($separator = NULL)
    {
        return $this->fields->errorFields($separator);
    }

    /**
     * Check if form could shown
     *
     * @return bool
     */
    public function showForm(): bool
    {
        return (!$this->isFilled() || !$this->isValid()) && !$this->isFatal();
    }


    /*********************/
    /** Message Methods **/
    /*********************/

    /**
     * Get Messages
     *
     * @param string $key
     * @param array $replaceArray Additional array for replacing
     *
     * @return string
     */
    public function message($key, $replaceArray = []): string
    {

        $text = $this->__call($key)
                ->or(option('microman.formblock.translations.' . I18n::locale() . '.' .$key))
                ->or(I18n::translate('form.block.' . $key));

        return Str::template($text, A::merge($this->fieldsWithPlaceholder('value'), $replaceArray));

    }

    /**
     * Returns error message
     *
     * @return string
     */
    public function errorMessage(): string
    {

        //Return fatal-error if there is one
        if ($this->isFatal())
            return $this->error;

        //Return invalid-message if form invalid
        if (!$this->isValid())
            return $this->message('invalid_message', ['fields' => $this->errorFields(', ')]);
    }

    /**
     * Returns success message
     *
     * @return string
     */
    public function successMessage(): string
    {
        if ($this->isSuccess()) {

            //Redirect if set in panel
            if ($this->redirect()->isTrue())
                return go($this->success_url());

            return $this->message('success_message');
        }

    }

    /**************************/
    /** Request Page Methods **/
    /**************************/

    /**
     * Get Page that is used for saving requests
     *
     * @return \Kirby\Cms\Page
     */
    public function getRequestContainer(): \Kirby\Cms\Page
    {
        $blockId = ($this->id());
        $container = $this->parent()->drafts()->find($blockId);

        if (!is_null($container))
            return $container;

        $this->kirby()->impersonate('kirby');

        //Create page - not existing
        return $this->parent()->createChild([
            'slug' => $blockId,
            'template' => 'formcontainer',
            'content' => ['name' => $this->message('name')]
        ]);
    }

    /**
     * Update the request as page
     *
     * @param array $input Changes
     */
    private function updateRequest(array $input = [])
    {
        try {
            if (is_a($this->requestPage, '\Kirby\Cms\Page')) {
                $this->requestPage->update($input);
            }
        } catch (\Throwable $error) {
            $this->setError("Error updating request: " . $error);
        }
    }

    /**
     * Save the request as page (get error if failed)
     *
     * @return string
     */
    private function saveRequest(): string
    {
        if (option('microman.formblock.disable_inbox')) {
            return "";
        }

        $container = $this->getRequestContainer();
        $formdata = json_encode($this->fieldsWithPlaceholder());

        if (!option('microman.formblock.verify_content')) {
            $formdata .= date('YmdHis', time());
        }

        $requestId = md5($formdata);

        //The request with that values already exists
        if ($container->drafts()->find($requestId))
            return $this->message('exists_message');

        try {
            site()->kirby()->impersonate('kirby');

            //TODO: Before Save
            $this->requestPage = $container->createChild([
                'slug' => $requestId,
                'template' => 'formrequest',
                'content' => [
                    'received' => date('Y-m-d H:i:s', time()),
                    'formdata' => $formdata
                ]
            ]);
            //TODO: After Save
        } catch (\Throwable $error) {
            $this->setError("Error saving request: " . $error->getMessage());
        }
        return "";
    }

    /******************/
    /** Send Methods **/
    /******************/

    /**
     * Send notification email to operator - returns error message if failed
     *
     * @param string|NULL $body Mailtext - set custom notification body if not set
     * @param string|NULL $recipent Recipent - set custom notification email if not set
     */
    public function sendNotification($body = NULL, $recipient = NULL)
    {
        if (option('microman.formblock.disable_notify')) {
            return;
        }

        if (is_null($body)) {
            $body = $this->message('notify_body');
        }

        if (is_null($recipient)) {
            $recipient = $this->message('notify_email');
        }

        try {
            //TODO: Before Notify
            $emailData = [
                'from' => option('microman.formblock.from_email'),
                'to' => $recipient,
                'subject' => $this->message('notify_subject'),
                'body' => [
                    'text' => Str::unhtml($body),
                    'html' => $body
                ]
            ];
            //TODO: After Notify

            if ($replyTo = $this->fieldByName('email', 'value')) {
                $emailData['replyTo'] = $replyTo;
            }

            site()->kirby()->email($emailData);

            $this->updateRequest(['notify-send' => date('Y-m-d H:i:s', time())]);

        } catch (\Throwable $error) {
            $this->setError("Error sending notification: " . $error->getMessage());
        }

    }

    /**
     * Send confirmation email to visitor - returns error message if failed
     *
     * @param string|NULL $body Mailtext - set custom notification body if not set
     * @param string|NULL $reply Reply - set custom reply email if not set
     */
    public function sendConfirmation($body = NULL, $reply =NULL)
    {

        if (option('microman.formblock.disable_confirm')) {
            return;
        }

        if (is_null($body)) {
            $body = $this->message('confirm_body');
        }

        if (is_null($reply)) {
            $reply = $this->message('confirm_email');
        }

        try {

            //TODO: Before Confirmation
            site()->kirby()->email([
                'from' => option('microman.formblock.from_email'),
                'to' => $this->fieldByName('email', 'value'),
                'replyTo' => $reply,
                'subject' => $this->message('confirm_subject'),
                'body' => [
                    'text' => Str::unhtml($body),
                    'html' => $body
                ]
            ]);

            //TODO: After Confirmation
            $this->updateRequest(['confirm-send' => date('Y-m-d H:i:s', time())]);

        } catch (\Throwable $error) {
            $this->setError("Error sending confirmation: " . $error->getMessage());
        }
    }

    /**
     * Throw mail error
     *
     * @param string $error Error message
     * @param bool $save Save error message to request
     *
     * @return string
     */
    public function setError($error = "An error occured", $save = true): string
    {
        if ($save) {
            $this->updateRequest(['error' => $error]);
        }
        return $this->error = option('debug') ? $error : $this->message('fatal_message');
    }


    /***************************/
    /** Let the magic happen! **/
    /***************************/

    /**
     * Save and Send the request
     */
    private function runProcess()
    {
        if ($this->isFilled() && $this->isValid()) {

            //Save request
            $saveRequest = $this->saveRequest();
            if ($saveRequest != "") {
                $this->error = $saveRequest;
            }

            // Send notification mail
            if (!option('microman.formblock.disable_notify') && !$this->isFatal()) {
                if($this->enable_notify()?->toBool()) {
                    $this->sendNotification();
                }
            }

            // Send notification mail
            if (!option('microman.formblock.disable_confirm') && !$this->isFatal()) {
                if($this->enable_confirm()?->toBool()) {
                    $this->sendConfirmation();
                }
            }

        }
    }


    /**
     * Controller for the formblock snippet
     *
     * @return array
     */
    public function controller(): array
    {
        return [
            'form'    => $this,
            'block'   => $this,
            'content' => $this->content(),
            'id'      => $this->id(),
            'prev'    => $this->prev(),
            'next'    => $this->next()
        ];
    }

}
