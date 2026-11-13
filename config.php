<?php
// Ajuste conforme seu XAMPP/WAMP (padrão: root sem senha)
const DB_HOST = 'localhost';
const DB_PORT = 3306;
const DB_NAME = 'farmacontrol';
const DB_USER = 'root';
const DB_PASS = '';
date_default_timezone_set('America/Sao_Paulo');

function conectar(): PDO {
    return new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}
