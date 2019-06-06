<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class IndexController
 * @package App\Controller
 */
class IndexController extends AbstractController
{
    /**
     * @var ParameterBagInterface
     */
    public $params;

    /**
     * IndexController constructor.
     * @param ParameterBagInterface $params
     */
    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    /**
     * @Route("/")
     * @return Response
     * @throws \Exception
     */
    public function index()
    {
        $number = random_int(0, 100);

        return $this->render('app.html.twig', [
            'number' => $number,
        ]);
    }

    /**
     * @Route(name="config", path="/config", methods="GET")
     * @return JsonResponse
     */
    function getConfig()
    {
        return $this->json([
            'search' => $this->params->get('e2ee_cloud')['search']['active'],
            'index_file_ext' => $this->params->get('e2ee_cloud')['search']['index_file_ext'],
        ]);
    }
}