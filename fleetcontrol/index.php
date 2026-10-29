<?php
// Remove o prefixo do subdiretório da REQUEST_URI
// dev: /Transportes/public | prod: /fleetcontrol | raiz: '' (string vazia)
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($scriptDir !== '' && strpos($_SERVER['REQUEST_URI'], $scriptDir) === 0) {
    $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen($scriptDir)) ?: '/';
}

//require_once '../vendor/autoload.php'; // se usar Composer para autoload, ou faça manualmente
require_once '../config/database.php';
require_once '../src/Core/Router.php';
require_once '../src/Core/Request.php';
require_once '../src/Core/Database.php';
require_once '../src/Helpers/JsonResponse.php';
require_once '../src/Helpers/Auth.php';

use Core\Router;
use Core\Request;

// Autoload manual para simplificar (caso não use Composer)
spl_autoload_register(function ($class) {
    $prefix = 'Controllers\\';
    $base_dir = __DIR__ . '/../src/Controllers/';
    if (strpos($class, $prefix) === 0) {
        $file = $base_dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) require $file;
    }
});

$router = new Router();
$request = new Request();

// Autenticação //Renomear as rotas
/*
$router->post('/Transportes/public/api/v1/auth/login', 'AuthController@login'); //Teste ok
$router->post('/Transportes/public/api/v1/auth/logout', 'AuthController@logout'); //Teste ok

// Motoristas (admin)
$router->get('/Transportes/public/api/v1/motoristas', 'MotoristaController@index');
$router->post('/Transportes/public/api/v1/motoristas', 'MotoristaController@store');

// Fretes/Viagens
$router->get('/Transportes/public/api/v1/fretes', 'FreteController@index'); //Teste ok
$router->post('/Transportes/public/api/v1/fretes', 'FreteController@store'); //Teste ok

// Abastecimentos
$router->get('/Transportes/public/api/v1/abastecimentos', 'AbastecimentoController@index'); //Teste ok
$router->post('/Transportes/public/api/v1/abastecimentos', 'AbastecimentoController@store'); //Teste ok

// Checklists
$router->post('/Transportes/public/api/v1/checklists', 'ChecklistController@store');

// Manutenções
$router->post('/Transportes/public/api/v1/manutencoes', 'ManutencaoController@store');
$router->put('/Transportes/public/api/v1/manutencoes/{id}', 'ManutencaoController@update');
*/

// Autenticação
$router->post('/Transportes/public/api/v1/auth/login', 'AuthController@login');
$router->post('/Transportes/public/api/v1/auth/logout', 'AuthController@logout');

// Motoristas
$router->get('/Transportes/public/api/v1/motoristas', 'MotoristaController@index');
$router->get('/Transportes/public/api/v1/motoristas/{id}', 'MotoristaController@show');
$router->post('/Transportes/public/api/v1/motoristas', 'MotoristaController@store');
$router->put('/Transportes/public/api/v1/motoristas/{id}', 'MotoristaController@update');
$router->delete('/Transportes/public/api/v1/motoristas/{id}', 'MotoristaController@destroy');

// Técnicos
$router->get('/Transportes/public/api/v1/tecnicos', 'TecnicoController@index');
$router->get('/Transportes/public/api/v1/tecnicos/{id}', 'TecnicoController@show');
$router->post('/Transportes/public/api/v1/tecnicos', 'TecnicoController@store');
$router->put('/Transportes/public/api/v1/tecnicos/{id}', 'TecnicoController@update');
$router->delete('/Transportes/public/api/v1/tecnicos/{id}', 'TecnicoController@destroy');

// Veículos
$router->get('/Transportes/public/api/v1/veiculos', 'VeiculoController@index');
$router->get('/Transportes/public/api/v1/veiculos/{placa}', 'VeiculoController@show');
$router->post('/Transportes/public/api/v1/veiculos', 'VeiculoController@store');
$router->put('/Transportes/public/api/v1/veiculos/{placa}', 'VeiculoController@update');
$router->delete('/Transportes/public/api/v1/veiculos/{placa}', 'VeiculoController@destroy');

// Documentos de Veículo
$router->get('/Transportes/public/api/v1/documentos-veiculo', 'DocumentoVeiculoController@index');
$router->get('/Transportes/public/api/v1/documentos-veiculo/{placa}/{descricao}', 'DocumentoVeiculoController@show');
$router->post('/Transportes/public/api/v1/documentos-veiculo', 'DocumentoVeiculoController@store');
$router->put('/Transportes/public/api/v1/documentos-veiculo/{placa}/{descricao}', 'DocumentoVeiculoController@update');
$router->delete('/Transportes/public/api/v1/documentos-veiculo/{placa}/{descricao}', 'DocumentoVeiculoController@destroy');

// Manutenções
$router->get('/Transportes/public/api/v1/manutencoes', 'ManutencaoController@index');
$router->get('/Transportes/public/api/v1/manutencoes/{id}', 'ManutencaoController@show');
$router->post('/Transportes/public/api/v1/manutencoes', 'ManutencaoController@store');
$router->put('/Transportes/public/api/v1/manutencoes/{id}', 'ManutencaoController@update');
$router->delete('/Transportes/public/api/v1/manutencoes/{id}', 'ManutencaoController@destroy');

// Arquivos de Manutenção
$router->get('/Transportes/public/api/v1/arquivos-manutencao', 'ArquivoManutencaoController@index');
$router->get('/Transportes/public/api/v1/arquivos-manutencao/{id}', 'ArquivoManutencaoController@show');
$router->post('/Transportes/public/api/v1/arquivos-manutencao', 'ArquivoManutencaoController@store');
$router->delete('/Transportes/public/api/v1/arquivos-manutencao/{id}', 'ArquivoManutencaoController@destroy');

// Fretes
$router->get('/Transportes/public/api/v1/fretes', 'FreteController@index');
$router->get('/Transportes/public/api/v1/fretes/{id}', 'FreteController@show');
$router->post('/Transportes/public/api/v1/fretes', 'FreteController@store');
$router->put('/Transportes/public/api/v1/fretes/{id}', 'FreteController@update');
$router->delete('/Transportes/public/api/v1/fretes/{id}', 'FreteController@destroy');

// Abastecimentos
$router->get('/Transportes/public/api/v1/abastecimentos', 'AbastecimentoController@index');
$router->get('/Transportes/public/api/v1/abastecimentos/{id}', 'AbastecimentoController@show');
$router->post('/Transportes/public/api/v1/abastecimentos', 'AbastecimentoController@store');
$router->put('/Transportes/public/api/v1/abastecimentos/{id}', 'AbastecimentoController@update');
$router->delete('/Transportes/public/api/v1/abastecimentos/{id}', 'AbastecimentoController@destroy');

// Checklists
$router->get('/Transportes/public/api/v1/checklists', 'ChecklistController@index');
$router->get('/Transportes/public/api/v1/checklists/{dataHora}/{placa}/{idMotorista}', 'ChecklistController@show');
$router->post('/Transportes/public/api/v1/checklists', 'ChecklistController@store');
$router->put('/Transportes/public/api/v1/checklists/{dataHora}/{placa}/{idMotorista}', 'ChecklistController@update');
$router->delete('/Transportes/public/api/v1/checklists/{dataHora}/{placa}/{idMotorista}', 'ChecklistController@destroy');


$router->dispatch(Request::capture());