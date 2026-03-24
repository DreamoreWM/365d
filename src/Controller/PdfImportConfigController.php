<?php

namespace App\Controller;

use App\Entity\PdfImportConfig;
use App\Repository\PdfImportConfigRepository;
use App\Service\PdfTextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/pdf-config')]
class PdfImportConfigController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PdfImportConfigRepository $repository,
        private PdfTextExtractor $extractor,
    ) {}

    #[Route('/', name: 'admin_pdf_config_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return $this->render('admin/pdf_config/index.html.twig', [
            'configs' => $this->repository->findBy([], ['priorite' => 'ASC', 'nom' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'admin_pdf_config_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $config = new PdfImportConfig();
        return $this->handleForm($request, $config, true);
    }

    #[Route('/{id}/edit', name: 'admin_pdf_config_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, PdfImportConfig $config): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return $this->handleForm($request, $config, false);
    }

    #[Route('/{id}/delete', name: 'admin_pdf_config_delete', methods: ['POST'])]
    public function delete(Request $request, PdfImportConfig $config): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if ($this->isCsrfTokenValid('delete' . $config->getId(), $request->request->get('_token'))) {
            $this->em->remove($config);
            $this->em->flush();
            $this->addFlash('success', 'Configuration supprimée.');
        }
        return $this->redirectToRoute('admin_pdf_config_index');
    }

    /** Upload un PDF exemple et retourne ses lignes en JSON */
    #[Route('/preview-pdf', name: 'admin_pdf_config_preview', methods: ['POST'])]
    public function previewPdf(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $file = $request->files->get('pdf');
        if (!$file) {
            return $this->json(['success' => false, 'error' => 'Aucun fichier'], 400);
        }
        $tmpPath = sys_get_temp_dir() . '/' . uniqid('pdfprev_') . '.pdf';
        $file->move(sys_get_temp_dir(), basename($tmpPath));
        $lines = $this->extractor->extractLines($tmpPath);
        @unlink($tmpPath);
        return $this->json([
            'success' => true,
            'lines'   => array_map(fn($i, $t) => ['index' => $i, 'text' => $t], array_keys($lines), $lines),
        ]);
    }

    private function handleForm(Request $request, PdfImportConfig $config, bool $isNew): Response
    {
        if ($request->isMethod('POST')) {
            $config->setNom(trim($request->request->get('nom', '')));
            $config->setIdentifiantTexte(trim($request->request->get('identifiantTexte', '')));
            $config->setPriorite((int) $request->request->get('priorite', 100));
            $config->setActif((bool) $request->request->get('actif', false));
            $config->setUpdatedAt(new \DateTimeImmutable());

            $rawMappings = $request->request->get('fieldMappings', '[]');
            $mappings    = json_decode($rawMappings, true) ?: [];
            $config->setFieldMappings($mappings);

            if (!$config->getNom() || !$config->getIdentifiantTexte()) {
                $this->addFlash('danger', 'Le nom et le texte identifiant sont obligatoires.');
            } else {
                $this->em->persist($config);
                $this->em->flush();
                $this->addFlash('success', $isNew ? 'Configuration créée.' : 'Configuration mise à jour.');
                return $this->redirectToRoute('admin_pdf_config_index');
            }
        }

        return $this->render('admin/pdf_config/form.html.twig', [
            'config' => $config,
            'isNew'  => $isNew,
        ]);
    }
}
