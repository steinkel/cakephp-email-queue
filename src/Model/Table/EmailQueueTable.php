<?php
namespace EmailQueue\Model\Table;

use Cake\Database\Expression\QueryExpression;
use Cake\Database\Schema\Table as Schema;
use Cake\I18n\FrozenTime;
use Cake\ORM\Table;
use EmailQueue\Database\Type\JsonType;

/**
 * EmailQueue Table
 *
 */
class EmailQueue extends Table {

    /**
     * {@inheritDocs}
     *
     */
    public function initialize(array $config = []) {
        Type::map('email_queue.json', JsonType::class);
    }

    /**
     * Stores a new email message in the queue
     *
     * @param mixed $to email or array of emails as recipients
     * @param array $data associative array of variables to be passed to the email template
     * @param array $options list of options for email sending. Possible keys:
     *
     * - subject : Email's subject
     * - send_at : date time sting representing the time this email should be sent at (in UTC)
     * - template :  the name of the element to use as template for the email message
     * - layout : the name of the layout to be used to wrap email message
     * - format: Type of template to use (html, text or both)
     * - config : the name of the email config to be used for sending
     *
     * @return bool
     */
    public function enqueue($to, array $data, $options = [])
    {
        $defaults = [
            'subject' => '',
            'send_at' => new FrozenTime('now', 'UTC'),
            'template' => 'default',
            'layout' => 'default',
            'theme' => '',
            'format' => 'both',
            'headers' => [],
            'template_vars' => $data,
            'config' => 'default'
        ];

        $email = $options + $defaults;
        if (!is_array($to)) {
            $to = [$to];
        }

        $emails = [];
        foreach ($to as $t) {
            $emails[] = ['to' => $t] + $email;
        }

        $emails = $this->newEntities($emails);
        return $this->connection()->transactional(function () use ($emails) {
            $failure = collection($emails)
                ->map(function ($email) {
                    return $this->save($email);
                })
                ->contains(false);

            return !$failure;
        });
    }

    /**
     * Returns a list of queued emails that needs to be sent
     *
     * @param integer $size, number of unset emails to return
     * @return array list of unsent emails
     */
    public function getBatch($size = 10) {
        return $this->connection()->transactional(function () use ($size) {
            $emails = $this->find()
                ->where([
                    $this->aliasField('sent') => false,
                    $this->aliasField('EmailQueue.send_tries'). ' <=' => 3,
                    $this->aliasField('EmailQueue.send_at'). ' <=' => new FrozenTime('now', 'UTC'),
                    $this->aliasField('EmailQueue.locked') => false
                ])
                ->order([$this->aliasField('created') => 'ASC']);

            $emails
                ->extract('id')
                ->through(function ($ids) {
                    $this->updateAll(['locked' => true], ['id IN' => $ids]);
                });

            return $emails;
        });
    }

    /**
     * Releases locks for all emails in $ids
     *
     * @param array|Traversable $ids The email ids to unlock
     * @return void
     */
    public function releaseLocks($ids) {
        $this->updateAll(['locked' => false], ['id' => $ids]);
    }

    /**
     * Releases locks for all emails in queue, useful for recovering from crashes
     *
     * @return void
     */
    public function clearLocks() {
        $this->updateAll(['locked' => false]);
    }

    /**
     * Marks an email from the queue as sent
     *
     * @param string $id, queued email id
     * @return boolean
     */
    public function success($id) {
        $this->updateAll(['sent' => true], ['id' => $id]);
    }

    /**
     * Marks an email from the queue as failed, and increments the number of tries
     *
     * @param string $id, queued email id
     * @return boolean
     */
    public function fail($id) {
        $this->updateAll(['send_tries' => new QueryExpression('send_tries + 1')], ['id' => $id]);
    }

    /**
     * Sets the column type for template_vars and headers to json
     *
     * @param Schema $schema The table description
     * @return Schema
     */
    protected function _initializeSchema(Schema $schema)
    {
        $schema->columnType('template_vars', 'email_queue.json');
        $schema->columnType('headers', 'email_queue.json');
        return $schema;
    }
}