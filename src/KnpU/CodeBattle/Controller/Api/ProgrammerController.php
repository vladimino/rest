<?php

namespace KnpU\CodeBattle\Controller\Api;

use Hateoas\Representation\CollectionRepresentation;
use KnpU\CodeBattle\Api\ApiProblem;
use KnpU\CodeBattle\Api\ApiProblemException;
use KnpU\CodeBattle\Controller\BaseController;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use KnpU\CodeBattle\Model\Programmer;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ProgrammerController extends BaseController
{

    protected function addRoutes(ControllerCollection $controllers)
    {
        $controllers->post('/api/programmers', array($this, 'newAction'));

        $controllers->get('/api/programmers/{nickname}', array($this, 'showAction'))
            ->bind('api_programmers_show');

        $controllers->get('/api/programmers', array($this, 'listAction'));

        $controllers->get('/api/programmers/{nickname}/battles', array($this, 'listBattlesAction'))
            ->bind('api_programmers_battles_list');

        // point PUT and PATCH at the same controller
        $controllers->put('/api/programmers/{nickname}', array($this, 'updateAction'));

        // PATCH isn't natively supported, hence the different syntax
        $controllers->match('/api/programmers/{nickname}', array($this, 'updateAction'))
            ->method('PATCH');

        $controllers->delete('/api/programmers/{nickname}', array($this, 'deleteAction'));
    }

    public function newAction(Request $request)
    {
        $this->enforceUserSecurity();

        $programmer = new Programmer();
        $this->handleRequest($request, $programmer);

        if ($errors = $this->validate($programmer)) {
            $this->throwApiProblemValidationException($errors);
        }

        $this->save($programmer);

        $response = $this->createApiResponse($programmer, 201);

        $programmerUrl = $this->generateUrl(
            'api_programmers_show', ['nickname' => $programmer->nickname]
        );
        $response->headers->set('Location', $programmerUrl);

        return $response;
    }

    public function showAction($nickname)
    {
        $programmer = $this->getProgrammerRepository()->findOneByNickname($nickname);

        if (!$programmer) {
            $this->throw404('Oh no! This programmer has deserted! We\'ll send a search party!');
        }
        $response = $this->createApiResponse($programmer);

        return $response;
    }

    public function listAction()
    {
        $programmers = $this->getProgrammerRepository()->findAll();

        $collection = new CollectionRepresentation(
            $programmers,
            'programmers',
            'programmers'
        );
        $response = $this->createApiResponse($collection, 200, 'json');

        return $response;
    }

    public function updateAction($nickname, Request $request)
    {
        $programmer = $this->getProgrammerRepository()->findOneByNickname($nickname);

        if (!$programmer) {
            $this->throw404('Oh no! This programmer has deserted! We\'ll send a search party!');
        }

        $this->enforceProgrammerOwnershipSecurity($programmer);

        $this->handleRequest($request, $programmer);

        if ($errors = $this->validate($programmer)) {
            $this->throwApiProblemValidationException($errors);
        }

        $this->save($programmer);

        $response = $this->createApiResponse($programmer);

        return $response;
    }

    public function deleteAction($nickname)
    {
        $programmer = $this->getProgrammerRepository()->findOneByNickname($nickname);

        if ($programmer) {
            $this->enforceProgrammerOwnershipSecurity($programmer);
            $this->delete($programmer);
        }

        return new Response(null, 204);
    }

    public function listBattlesAction($nickname)
    {
        $programmer = $this->getProgrammerRepository()->findOneByNickname($nickname);

        if (!$programmer) {
            $this->throw404('Oh no! This programmer has deserted! We\'ll send a search party!');
        }

        $battles = $this->getBattleRepository()
            ->findAllBy(array('programmerId' => $programmer->id));

        $collection = new CollectionRepresentation(
            $battles,
            'battles',
            'battles'
        );

        $response = $this->createApiResponse($collection);

        return $response;
    }

    /**
     * Reads data from the Request, updates the Programmer and saves it.
     *
     * @param Request $request
     * @param Programmer $programmer
     */
    private function handleRequest(Request $request, Programmer $programmer)
    {
        $data = $this->decodeRequestBodyIntoParameters($request);
        $isNew = !$programmer->id;

        // determine which properties should be changeable on this request
        $apiProperties = array('avatarNumber', 'tagLine');
        if ($isNew) {
            $apiProperties[] = 'nickname';
        }

        // update the properties
        foreach ($apiProperties as $property) {
            // if a property is missing on PATCH, that's ok - just skip it
            if (!($data->has($property)) && $request->isMethod('PATCH')) {
                continue;
            }

            $programmer->$property = $data->get($property);
        }

        $programmer->userId = $this->getLoggedInUser()->id;
    }

}
