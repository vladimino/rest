<?php

namespace KnpU\CodeBattle\Controller\Api;

use Hateoas\Representation\CollectionRepresentation;
use Hateoas\Representation\PaginatedRepresentation;
use KnpU\CodeBattle\Api\ApiProblem;
use KnpU\CodeBattle\Api\ApiProblemException;
use KnpU\CodeBattle\Controller\BaseController;
use KnpU\CodeBattle\Model\Homepage;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use KnpU\CodeBattle\Model\Programmer;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ProgrammerController extends BaseController
{

    protected function addRoutes(ControllerCollection $controllers)
    {
        // the homepage - put in this controller for simplicity
        $controllers->get('/api', array($this, 'homepageAction'))
            ->bind('api_homepage');

        $controllers->post('/api/programmers', array($this, 'newAction'));

        $controllers->get('/api/programmers/{nickname}', array($this, 'showAction'))
            ->bind('api_programmers_show');

        $controllers->get('/api/programmers', array($this, 'listAction'))
            ->bind('api_programmers_list');

        $controllers->get('/api/programmers/{nickname}/battles', array($this, 'listBattlesAction'))
            ->bind('api_programmers_battles_list');

        // point PUT and PATCH at the same controller
        $controllers->put('/api/programmers/{nickname}', array($this, 'updateAction'));

        // PATCH isn't natively supported, hence the different syntax
        $controllers->match('/api/programmers/{nickname}', array($this, 'updateAction'))
            ->method('PATCH');

        $controllers->delete('/api/programmers/{nickname}', array($this, 'deleteAction'));
    }

    public function homepageAction()
    {
        $homepage = new Homepage();
        return $this->createApiResponse($homepage);
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

    public function listAction(Request $request)
    {
        $nicknameFilter = $request->query->get('nickname');
        if ($nicknameFilter) {
            $programmers = $this->getProgrammerRepository()
                ->findAllLike(array('nickname' => '%'.$nicknameFilter.'%'));
        } else {
            $programmers = $this->getProgrammerRepository()->findAll();
        }

        $limit = $request->get('limit', 5);
        $page = $request->get('page', 1);
        // my manual, silly pagination logic. Use a real library
        $offset = ($page - 1) * $limit;
        $numberOfPages = (int)ceil(count($programmers) / $limit);

        $collection = new CollectionRepresentation(
            array_slice($programmers, $offset, $limit),
            'programmers',
            'programmers'
        );

        $paginated = new PaginatedRepresentation(
            $collection,
            'api_programmers_list',
            array('nickname' => $nicknameFilter),
            $page,
            $limit,
            $numberOfPages
        );

        $response = $this->createApiResponse($paginated, 200, 'json');

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
