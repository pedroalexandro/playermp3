

CREATE TABLE LISTAS (
    CHV         SEQCHV NOT NULL /* SEQCHV = INTEGER NOT NULL */,
    NOME        V60 /* V60 = VARCHAR(60) */,
    REVISADOEM  DATA /* DATA = DATE */,
    CAD         DATA /* DATA = DATE */,
    NOTA        INTEIROPEQUENO /* INTEIROPEQUENO = SMALLINT */,
    QDADEARQ    INTEIRO /* INTEIRO = INTEGER */
);



/******************************************************************************/
/*                             Unique constraints                             */
/******************************************************************************/

ALTER TABLE LISTAS ADD UNIQUE (NOME);


CREATE TABLE LISTASITENS (
    CHV         SEQCHV NOT NULL /* SEQCHV = INTEGER NOT NULL */,
    CHVARQUIVO  INTEIRO /* INTEIRO = INTEGER */,
    CHVLISTA    INTEIRO /* INTEIRO = INTEGER */,
    ORDEM       INTEIRO /* INTEIRO = INTEGER */
);