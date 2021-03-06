<?php

/*
 * This file is part of the pvrEzComment package.
 *
 * (c) Philippe Vincent-Royol <vincent.royol@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace pvr\EzCommentBundle\Comment;

use eZ\Publish\Core\Persistence\Legacy\EzcDbHandler;
use eZ\Publish\Core\Repository\Values\User\User as EzUser;
use eZ\Publish\Core\MVC\Symfony\Locale\LocaleConverter;
use pvr\EzCommentBundle\Comment\pvrEzCommentManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Form\Form;

class pvrEzCommentManager implements pvrEzCommentManagerInterface
{
    const COMMENT_WAITING   = 0;
    const COMMENT_ACCEPT    = 1;
    const COMMENT_REJECTED  = 2;
    const ANONYMOUS_USER    = 10;

    protected $anonymous_access;
    protected $moderating;
    protected $moderate_subject;
    protected $moderate_from;
    protected $moderate_to;
    protected $moderate_template;
    protected $isNotify;
    protected $container;
    protected $translator;

    public function __construct( $anonymous_access = false, $moderating = false,
                                 $moderate_subject, $moderate_from, $moderate_to, $moderate_template,
                                 $isNotify, ContainerInterface $container )
    {
        $this->anonymous_access     = $anonymous_access;
        $this->moderating           = $moderating;
        $this->moderate_subject     = $moderate_subject;
        $this->moderate_from        = $moderate_from;
        $this->moderate_to          = $moderate_to;
        $this->moderate_template    = $moderate_template;
        $this->isNotify             = $isNotify;
        $this->container            = $container;
        $this->translator           = $this->container->get( 'translator' );
    }

    /**
     * Check if connection is an instance of EzcDbHandler
     *
     * @param $connection
     * @throws \InvalidArgumentException
     */
    protected function checkConnection ( $connection )
    {
        if ( !( $connection instanceof EzcDbHandler ) )
        {
            throw new \InvalidArgumentException(
                $this->translator->trans( 'Connection is not a valid %ezdbhandler%',
                    array( '%ezdbhandler%' => 'eZ\\Publish\\Core\\Persistence\\Legacy\\EzcDbHandler' )
                )
            );
        }
    }

    /**
     * Get list of comments depending of contentId and status
     *
     * @param $connection Get connection to eZ Publish Database
     * @param $contentId Get content Id to fetch comments
     * @param array $viewParameters
     * @param int $status
     * @return mixed Array or false
     */
    public function getComments( $connection, $contentId, $viewParameters = array(), $status = self::COMMENT_ACCEPT )
    {
        $this->checkConnection( $connection );

        $selectQuery = $connection->createSelectQuery();

        $column = "created";
        $sort   = $selectQuery::DESC;

        // Configure how to sort things
        if ( !empty( $viewParameters ) )
        {
            if ( $viewParameters['cSort'] == "author" )
                $column = "name";
            if ( $viewParameters['cOrder'] == 'asc' )
                $sort = $selectQuery::ASC;
        }

        $selectQuery->select(
            $connection->quoteColumn( 'id' ),
            $connection->quoteColumn( 'created' ),
            $connection->quoteColumn( 'user_id' ),
            $connection->quoteColumn( 'name' ),
            $connection->quoteColumn( 'email' ),
            $connection->quoteColumn( 'url' ),
            $connection->quoteColumn( 'text' ),
            $connection->quoteColumn( 'title' )
        )->from(
                $connection->quoteTable( 'ezcomment' )
            )->where(
                $selectQuery->expr->lAnd(
                    $selectQuery->expr->eq(
                        $connection->quoteColumn( 'contentobject_id' ),
                        $selectQuery->bindValue( $contentId, null, \PDO::PARAM_INT )
                    ),
                    $selectQuery->expr->eq(
                        $connection->quoteColumn( 'status' ),
                        $selectQuery->bindValue( $status, null, \PDO::PARAM_INT )
                    )
                )
            )->orderBy( $column, $sort );
        $statement = $selectQuery->prepare();
        $statement->execute();

        return $statement->fetchAll( \PDO::FETCH_ASSOC );
    }

    /**
     * Add a comment via an ezuser
     *
     * @param $connection
     * @param Request $request
     * @param EzUser $currentUser
     * @param LocaleConverter $localeService
     * @param array $data
     * @param null $contentId
     * @param null $sessionId
     */
    public function addComment( $connection, Request $request,
                                EzUser $currentUser, LocaleConverter $localeService,
                                $data = array(), $contentId = null, $sessionId = null )
    {
        $this->checkConnection( $connection );

        $languageCode = $localeService->convertToEz( $request->getLocale() );
        $languageId   = $this->getLanguageId( $connection, $languageCode );

        $created    = $modified = \Time();
        $userId     = $currentUser->versionInfo->contentInfo->id;
        $sessionKey = $sessionId;
        $ip         = $request->getClientIp();
        $parentCommentId = 0;
        $name       = $currentUser->versionInfo->contentInfo->name;
        $email      = $currentUser->email;
        $url        = "";
        $text       = $data[ $this->translator->trans( 'message' )];
        $status     = $this->hasModeration() ? self::COMMENT_WAITING : self::COMMENT_ACCEPT;;
        $title      = "";

        $selectQuery = $connection->createInsertQuery();

        $selectQuery->insertInto( 'ezcomment' )
            ->set( 'language_id',       $selectQuery->bindValue( $languageId ))
            ->set( 'created',           $selectQuery->bindValue( $created ))
            ->set( 'modified',          $selectQuery->bindValue( $modified ))
            ->set( 'user_id',           $selectQuery->bindValue( $userId ))
            ->set( 'session_key',       $selectQuery->bindValue( $sessionKey ))
            ->set( 'ip',                $selectQuery->bindValue( $ip ))
            ->set( 'contentobject_id',  $selectQuery->bindValue( $contentId ))
            ->set( 'parent_comment_id', $selectQuery->bindValue( $parentCommentId ))
            ->set( 'name',              $selectQuery->bindValue( $name ))
            ->set( 'email',             $selectQuery->bindValue( $email ))
            ->set( 'url',               $selectQuery->bindValue( $url ))
            ->set( 'text',              $selectQuery->bindValue( $text ))
            ->set( 'status',            $selectQuery->bindValue( $status ))
            ->set( 'title',             $selectQuery->bindValue( $title ));
        $statement = $selectQuery->prepare();
        $statement->execute();

        return $connection->lastInsertId();
    }

    /**
     * Add an anonymous comment
     *
     * @param $connection
     * @param Request $request
     * @param LocaleConverter $localeService
     * @param array $data
     * @param $contentId
     * @param null $sessionId
     */
    public function addAnonymousComment( $connection, Request $request, LocaleConverter $localeService,
                                         array $data, $contentId, $sessionId = null )
    {
        $this->checkConnection( $connection );

        $languageCode = $localeService->convertToEz( $request->getLocale() );
        $languageId   = $this->getLanguageId( $connection, $languageCode );

        $created    = $modified = \Time();
        $userId     = self::ANONYMOUS_USER;
        $sessionKey = $sessionId;
        $ip         = $request->getClientIp();
        $parentCommentId = 0;
        $name       = $data[ $this->translator->trans( 'name' )];
        $email      = $data[ $this->translator->trans( 'email')];
        $url        = "";
        $text       = $data[ $this->translator->trans( 'message' )];
        $status     = $this->hasModeration() ? self::COMMENT_WAITING : self::COMMENT_ACCEPT;
        $title      = "";

        $selectQuery = $connection->createInsertQuery();

        $selectQuery->insertInto( 'ezcomment' )
            ->set( 'language_id',       $selectQuery->bindValue( $languageId ))
            ->set( 'created',           $selectQuery->bindValue( $created ))
            ->set( 'modified',          $selectQuery->bindValue( $modified ))
            ->set( 'user_id',           $selectQuery->bindValue( $userId ))
            ->set( 'session_key',       $selectQuery->bindValue( $sessionKey ))
            ->set( 'ip',                $selectQuery->bindValue( $ip ))
            ->set( 'contentobject_id',  $selectQuery->bindValue( $contentId ))
            ->set( 'parent_comment_id', $selectQuery->bindValue( $parentCommentId ))
            ->set( 'name',              $selectQuery->bindValue( $name ))
            ->set( 'email',             $selectQuery->bindValue( $email ))
            ->set( 'url',               $selectQuery->bindValue( $url ))
            ->set( 'text',              $selectQuery->bindValue( $text ))
            ->set( 'status',            $selectQuery->bindValue( $status ))
            ->set( 'title',             $selectQuery->bindValue( $title ));
        $statement = $selectQuery->prepare();
        $statement->execute();

        return $connection->lastInsertId();
    }


    /**
     * Create an anonymous form
     *
     * @return mixed
     */
    public function createAnonymousForm()
    {
        $collectionConstraint = new Collection( array(
            $this->translator->trans( 'name' ) => new NotBlank(
                array( "message" => $this->translator->trans( "Could not be empty" ) )
            ),
            $this->translator->trans( 'email' ) => new Email(
                array( "message" => $this->translator->trans( "This is not a valid email" ) )
            ),
            $this->translator->trans( 'message' ) => new NotBlank(
                array( "message" => $this->translator->trans( "Could not be empty" ) )
            ),
        ));

        $form = $this->container->get( 'form.factory' )->createBuilder( 'form', null, array(
            'constraints' => $collectionConstraint
        ))->add( $this->translator->trans( 'name' ), 'text')
            ->add( $this->translator->trans( 'email' ), 'email')
            ->add( $this->translator->trans( 'message' ), 'textarea' )
            ->add( $this->translator->trans( 'captcha' ), 'captcha',
                array( 'as_url' => true, 'reload' => true )
            )
            ->getForm();

        return $form;
    }

    /**
     * Create an ezuser form
     *
     * @return mixed
     */
    public function createUserForm()
    {
        $collectionConstraint = new Collection( array(
            $this->translator->trans( 'message' ) => new NotBlank(
                array( "message" => $this->translator->trans( "Could not be empty" ) )
            ),
        ));

        $form = $this->container->get( 'form.factory' )->createBuilder( 'form', null, array(
            'constraints' => $collectionConstraint
        ))->add( $this->translator->trans( 'message' ), 'textarea' )
            ->getForm();

        return $form;
    }

    /**
     * Get validation error from form
     *
     * @param \Symfony\Component\Form\Form $form the form
     * @return array errors messages
     */
    public function getErrorMessages( Form $form )
    {
        $errors = array();
        foreach ($form->getErrors() as $key => $error) {
            $template = $error->getMessageTemplate();
            $parameters = $error->getMessageParameters();

            foreach($parameters as $var => $value){
                $template = str_replace($var, $value, $template);
            }

            $errors[$key] = $template;
        }
        foreach ($form->all() as $key => $child) {
            /** @var $child Form */
            if ($err = $this->getErrorMessages($child)) {
                $errors[$key] = $err;
            }
        }
        
        return $errors;
    }

    /**
     * Send message to admin(s)
     */
    public function sendMessage( $data, $user, $contentId, $sessionId, $commentId )
    {
        if ($user == null)
        {
            $name = $data[ $this->translator->trans( 'name' )];
            $email = $data[ $this->translator->trans( 'email' )];
        }
        else
        {
            $name   = $user->versionInfo->contentInfo->name;
            $email  = $user->email;
        }

        $encrypt_service = $this->container->get( 'pvr_ezcomment.encryption' );
        $encodeSession = $encrypt_service->encode( $sessionId );

        $approve_url = $this->container->get( 'router' )->generate(
            'pvrezcomment_moderation',
            array(
                'contentId' => $contentId,
                'sessionHash' => $encodeSession,
                'action' => 'approve',
                'commentId' => $commentId
            ),
            true
        );
        $reject_url = $this->container->get( 'router' )->generate(
            'pvrezcomment_moderation',
            array(
                'contentId' => $contentId,
                'sessionHash' => $encodeSession,
                'action' => 'reject',
                'commentId' => $commentId
            ),
            true
        );

        $message = \Swift_Message::newInstance()
            ->setSubject( $this->moderate_subject )
            ->setFrom( $this->moderate_from )
            ->setTo( $this->moderate_to )
            ->setBody(
                $this->container->get( 'templating' )->render( $this->moderate_template, array(
                    "name"  => $name,
                    "email" => $email,
                    "comment" => $data[ $this->translator->trans( 'message' )],
                    "approve_url" => $approve_url,
                    "reject_url" => $reject_url
                ))
            );
        $this->container->get( 'mailer' )->send( $message );
    }

    /**
     * Check if status of comment is on waiting
     *
     * @param $contentId
     * @param $sessionHash
     * @param $connection
     * @return bool
     */
    public function canUpdate( $contentId, $sessionHash, $connection, $commentId )
    {
        $this->checkConnection( $connection );

        $encrypt_service = $this->container->get( 'pvr_ezcomment.encryption' );
        $session_id = $encrypt_service->decode( $sessionHash );

        $selectQuery = $connection->createSelectQuery();

        $selectQuery->select(
            $connection->quoteColumn( 'id' )
        )->from(
                $connection->quoteTable( 'ezcomment' )
            )->where(
                $selectQuery->expr->lAnd(
                    $selectQuery->expr->eq(
                        $connection->quoteColumn( 'contentobject_id' ),
                        $selectQuery->bindValue( $contentId, null, \PDO::PARAM_INT )
                    ),
                    $selectQuery->expr->eq(
                        $connection->quoteColumn( 'session_key' ),
                        $selectQuery->bindValue( $session_id, null, \PDO::PARAM_INT )
                    ),
                    $selectQuery->expr->eq(
                        $connection->quoteColumn( 'status' ),
                        $selectQuery->bindValue( self::COMMENT_WAITING, null, \PDO::PARAM_INT )
                    ),
                    $selectQuery->expr->eq(
                        $connection->quoteColumn( 'id' ),
                        $selectQuery->bindValue( $commentId, null, \PDO::PARAM_INT )
                    )
                )
            );
        $statement = $selectQuery->prepare();
        $statement->execute();

        $row = $statement->fetch();

        return $row !== false ? true : false;
    }

    /**
     * Update status of a comment
     *
     * @param $connection
     * @param $commentId
     * @param int $status
     * @return mixed
     */
    public function updateStatus( $connection, $commentId, $status = self::COMMENT_ACCEPT )
    {
        $this->checkConnection( $connection );

        $updateQuery = $connection->createUpdateQuery();

        $updateQuery->update(
            $connection->quoteTable( 'ezcomment' )
        )->set(
            $connection->quoteColumn( 'status' ),
                $updateQuery->bindValue( $status, null, \PDO::PARAM_INT )
            )->where(
                $updateQuery->expr->lAnd(
                    $updateQuery->expr->eq(
                        $connection->quoteColumn( 'id' ),
                        $updateQuery->bindValue( $commentId, null, \PDO::PARAM_INT )
                    ),
                    $updateQuery->expr->eq(
                        $connection->quoteColumn( 'status' ),
                        $updateQuery->bindValue( self::COMMENT_WAITING, null, \PDO::PARAM_INT )
                    )
                )
            );
        $statement = $updateQuery->prepare();
        return $statement->execute();
    }

    /**
     * @param int $contentId
     * @param EzcDbHandler $handler
     * @return int
     */
    public function getCountComments( $contentId, EzcDbHandler $handler )
    {
        $this->checkConnection( $handler );

        $selectQuery = $handler->createSelectQuery();

        $selectQuery->select( '*' )
            ->from(
                $handler->quoteTable( 'ezcomment' )
            )->where(
                $selectQuery->expr->lAnd(
                    $selectQuery->expr->eq(
                        $handler->quoteColumn( 'contentobject_id' ),
                        $selectQuery->bindValue( $contentId, null, \PDO::PARAM_INT )
                    ),
                    $selectQuery->expr->eq(
                        $handler->quoteColumn( 'status' ),
                        $selectQuery->bindValue( self::COMMENT_ACCEPT, null, \PDO::PARAM_INT )
                    )
                )
            );

        $statement = $selectQuery->prepare();
        $statement->execute();

        return $statement->rowCount();
    }

    /**
     * @return bool
     */
    public function hasAnonymousAccess()
    {
        return $this->anonymous_access;
    }

    /**
     * @return bool
     */
    public function hasModeration()
    {
        return $this->moderating;
    }


    /**
     * Get list of last comments
     *
     * @param $connection Get connection to eZ Publish Database
     * @param int $limit
     *
     * @return mixed Array or false
     */
    public function getLastComments( $connection, $limit = 5 )
    {
        $this->checkConnection( $connection );

        /** @var \ezcQuerySelect $selectQuery */
        $selectQuery = $connection->createSelectQuery();

        $column = "created";
        $sort   = $selectQuery::DESC;

        $selectQuery->select(
            $connection->quoteColumn( 'id' ),
            $connection->quoteColumn( 'created' ),
            $connection->quoteColumn( 'contentobject_id' ),
            $connection->quoteColumn( 'user_id' ),
            $connection->quoteColumn( 'name' ),
            $connection->quoteColumn( 'email' ),
            $connection->quoteColumn( 'url' ),
            $connection->quoteColumn( 'text' ),
            $connection->quoteColumn( 'title' )
        )->from(
                $connection->quoteTable( 'ezcomment' )
            )->where(
                $selectQuery->expr->eq(
                    $connection->quoteColumn( 'status' ),
                    $selectQuery->bindValue( self::COMMENT_ACCEPT, null, \PDO::PARAM_INT )
                )
            )->orderBy( $column, $sort )
             ->limit( $limit );

        $statement = $selectQuery->prepare();
        $statement->execute();

        return $statement->fetchAll( \PDO::FETCH_ASSOC );
    }

    /**
     * Get list of last comments
     *
     * @param $connection Get connection to eZ Publish Database
     * @param int $limit
     *
     * @return mixed Array or false
     */
    public function getLastCommentsByUser( $connection, $userId, $limit = 5 )
    {
        $this->checkConnection( $connection );

        /** @var \ezcQuerySelect $selectQuery */
        $selectQuery = $connection->createSelectQuery();

        $column = "created";
        $sort   = $selectQuery::DESC;

        $selectQuery->select(
            $connection->quoteColumn( 'id' ),
            $connection->quoteColumn( 'created' ),
            $connection->quoteColumn( 'contentobject_id' ),
            $connection->quoteColumn( 'user_id' ),
            $connection->quoteColumn( 'name' ),
            $connection->quoteColumn( 'email' ),
            $connection->quoteColumn( 'url' ),
            $connection->quoteColumn( 'text' ),
            $connection->quoteColumn( 'title' )
        )->from(
                $connection->quoteTable( 'ezcomment' )
            )->where(
                $selectQuery->expr->lAnd(
                    $selectQuery->expr->eq(
                        $connection->quoteColumn( 'status' ),
                        $selectQuery->bindValue( self::COMMENT_ACCEPT, null, \PDO::PARAM_INT )
                    ),
                    $selectQuery->expr->eq(
                        $connection->quoteColumn( 'user_id' ),
                        $selectQuery->bindValue( $userId, null, \PDO::PARAM_INT )
                    )
                )
            )->orderBy( $column, $sort )
             ->limit( $limit );

        $statement = $selectQuery->prepare();
        $statement->execute();

        return $statement->fetchAll( \PDO::FETCH_ASSOC );
    }


    /**
     * Get ezcontent_language Id
     * @param \eZ\Publish\Core\Persistence\Legacy\EzcDbHandler $connection
     * @param $languageCode
     * @return int
     */
    protected function getLanguageId( $connection, $languageCode ) {
        /** @var \ezcQuerySelect $selectQuery */
        $selectQuery = $connection->createSelectQuery();

        $selectQuery->select(
            $connection->quoteColumn( 'id' )
        )->from(
                $connection->quoteTable( 'ezcontent_language' )
            )->where(
                $selectQuery->expr->eq(
                    $connection->quoteColumn( 'locale' ),
                    $selectQuery->bindValue( $languageCode, null, \PDO::PARAM_STR )
                )
            );
        $statement = $selectQuery->prepare();
        $statement->execute();

        $row = $statement->fetch();
        if( isset($row['id']) ) {
            return $row['id'];
        } {
            return 0;
        }
    }
}