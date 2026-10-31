<?php
// Remove o prefixo do subdiretório da REQUEST_URI
// dev: /Transportes/public | prod: /fleetcontrol | raiz: '' (string vazia)
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($scriptDir !== '' && strpos($_SERVER['REQUEST_URI'], $scriptDir) === 0) {
    $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen($scriptDir)) ?: '/';
}
define('API', '/api/v1');

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

// Autenticação
$router->post(API .'/auth/login', 'AuthController@login');
$router->post(API .'/auth/logout', 'AuthController@logout');

// Motoristas
$router->get(API .'/motoristas', 'MotoristaController@index');
$router->get(API .'/motoristas/{id}', 'MotoristaController@show');
$router->post(API .'/motoristas', 'MotoristaController@store');
$router->put(API .'/motoristas/{id}', 'MotoristaController@update');
$router->delete(API .'/motoristas/{id}', 'MotoristaController@destroy');

// Técnicos
$router->get(API .'/tecnicos', 'TecnicoController@index');
$router->get(API .'/tecnicos/{id}', 'TecnicoController@show');
$router->post(API .'/tecnicos', 'TecnicoController@store');
$router->put(API .'/tecnicos/{id}', 'TecnicoController@update');
$router->delete(API .'/tecnicos/{id}', 'TecnicoController@destroy');

// Veículos
$router->get(API .'/veiculos', 'VeiculoController@index');
$router->get(API .'/veiculos/{placa}', 'VeiculoController@show');
$router->post(API .'/veiculos', 'VeiculoController@store');
$router->put(API .'/veiculos/{placa}', 'VeiculoController@update');
$router->delete(API .'/veiculos/{placa}', 'VeiculoController@destroy');

// Documentos de Veículo
$router->get(API .'/documentos-veiculo', 'DocumentoVeiculoController@index');
$router->get(API .'/documentos-veiculo/{placa}/{descricao}', 'DocumentoVeiculoController@show');
$router->post(API .'/documentos-veiculo', 'DocumentoVeiculoController@store');
$router->put(API .'/documentos-veiculo/{placa}/{descricao}', 'DocumentoVeiculoController@update');
$router->delete(API .'/documentos-veiculo/{placa}/{descricao}', 'DocumentoVeiculoController@destroy');

// Manutenções
$router->get(API .'/manutencoes', 'ManutencaoController@index');
$router->get(API .'/manutencoes/{id}', 'ManutencaoController@show');
$router->post(API .'/manutencoes', 'ManutencaoController@store');
$router->put(API .'/manutencoes/{id}', 'ManutencaoController@update');
$router->delete(API .'/manutencoes/{id}', 'ManutencaoController@destroy');

// Arquivos de Manutenção
$router->get(API .'/arquivos-manutencao', 'ArquivoManutencaoController@index');
$router->get(API .'/arquivos-manutencao/{id}', 'ArquivoManutencaoController@show');
$router->post(API .'/arquivos-manutencao', 'ArquivoManutencaoController@store');
$router->delete(API .'/arquivos-manutencao/{id}', 'ArquivoManutencaoController@destroy');

// Fretes
$router->get(API .'/fretes', 'FreteController@index');
$router->get(API .'/fretes/{id}', 'FreteController@show');
$router->post(API .'/fretes', 'FreteController@store');
$router->put(API .'/fretes/{id}', 'FreteController@update');
$router->delete(API .'/fretes/{id}', 'FreteController@destroy');

// Abastecimentos
$router->get(API .'/abastecimentos', 'AbastecimentoController@index');
$router->get(API .'/abastecimentos/{id}', 'AbastecimentoController@show');
$router->post(API .'/abastecimentos', 'AbastecimentoController@store');
$router->put(API .'/abastecimentos/{id}', 'AbastecimentoController@update');
$router->delete(API .'/abastecimentos/{id}', 'AbastecimentoController@destroy');

// Checklists
$router->get(API .'/checklists', 'ChecklistController@index');
$router->get(API .'/checklists/{dataHora}/{placa}/{idMotorista}', 'ChecklistController@show');
$router->post(API .'/checklists', 'ChecklistController@store');
$router->put(API .'/checklists/{dataHora}/{placa}/{idMotorista}', 'ChecklistController@update');
$router->delete(API .'/checklists/{dataHora}/{placa}/{idMotorista}', 'ChecklistController@destroy');


$router->dispatch(Request::capture());